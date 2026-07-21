<?php

namespace App\Jobs;

use App\Enums\QueryExportStatus;
use App\Models\Query;
use App\Models\QueryExport;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\QueryExportRunner;
use App\Services\WebhookDispatcher;
use App\Services\XlsxWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Streams one saved query to a CSV file off the request cycle, paginating Oracle
 * in the reader's own scope up to a bounded row cap. Read-only: the tenant and
 * its credentials are never the query author's.
 */
class RunQueryExport implements ShouldQueue
{
    use Queueable;

    /** Exports re-read Oracle and are non-idempotent; a failure is reported, not retried. */
    public int $tries = 1;

    /** A large paginated export can run a while; a configurable ceiling is deferred. */
    public int $timeout = 600;

    public function __construct(
        public QueryExport $export,
        public string $tenantKey,
    ) {}

    public function handle(
        FusionManager $fusion,
        OracleQueryTool $tool,
        QueryExportRunner $runner,
        AuditRecorder $audit,
        WebhookDispatcher $webhooks,
    ): void {
        $export = $this->export->fresh();

        if ($export === null || $export->status->isTerminal()) {
            return;
        }

        $user = $export->user;
        $query = $export->executedQuery;

        if ($export->isCancellationRequested()) {
            $this->markCancelled($export, $user, $query, $audit);

            return;
        }

        $export->forceFill([
            'status' => QueryExportStatus::Running,
            'started_at' => now(),
        ])->save();

        $scopedFusion = $fusion->forUser($user);
        $scopedTool = $tool->forUser($user);
        $format = $export->format ?: 'csv';
        $extension = match ($format) {
            'xlsx' => 'xml', // SpreadsheetML — .xml opens as xlsx in Excel
            'json' => 'json',
            default => 'csv',
        };

        $disk = Storage::disk(QueryExport::DISK);
        $relativePath = 'exports/'.$user->id.'/'.Str::uuid()->toString().'.'.$extension;
        $disk->makeDirectory('exports/'.$user->id);
        $handle = fopen($disk->path($relativePath), 'w');

        if ($handle === false) {
            $this->markFailed($export, $user, $query, $audit);

            return;
        }

        try {
            $outcome = $this->stream(
                $export,
                $query,
                $runner,
                $scopedFusion,
                $scopedTool,
                $handle,
                $format,
            );
        } catch (InvalidArgumentException|RuntimeException) {
            fclose($handle);
            $disk->delete($relativePath);
            $this->markFailed($export, $user, $query, $audit);

            return;
        }

        fclose($handle);

        if ($outcome['cancelled']) {
            $disk->delete($relativePath);
            $this->markCancelled($export, $user, $query, $audit);

            return;
        }

        $export->forceFill([
            'status' => QueryExportStatus::Completed,
            'row_count' => $outcome['rows'],
            'truncated' => $outcome['truncated'],
            'file_path' => $relativePath,
            'file_size' => $disk->size($relativePath),
            'expires_at' => now()->addDays(QueryExport::RETENTION_DAYS),
            'finished_at' => now(),
        ])->save();

        // Lot 12D — webhook event
        $export->refresh();
        $webhooks->dispatchExportReady($export);

        $audit->record($user, 'query.export_completed', $query, [
            'query_export_id' => $export->id,
            'tenant_key' => $this->tenantKey,
            'row_count' => $outcome['rows'],
            'truncated' => $outcome['truncated'],
        ]);
    }

    /**
     * Last-resort net for a timeout or fatal crash the handler could not catch.
     */
    public function failed(?Throwable $exception): void
    {
        $export = $this->export->fresh();

        if ($export === null || $export->status->isTerminal()) {
            return;
        }

        $export->forceFill([
            'status' => QueryExportStatus::Failed,
            'error_code' => 'export_error',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Paginate Oracle, writing each page to the handle until exhausted, the
     * row cap is hit, or a cooperative cancellation is requested between pages.
     *
     * @param  resource  $handle
     * @return array{rows: int, truncated: bool, cancelled: bool}
     */
    private function stream(
        QueryExport $export,
        Query $query,
        QueryExportRunner $runner,
        FusionManager $fusion,
        OracleQueryTool $tool,
        $handle,
        string $format,
    ): array {
        $columns = null;
        $written = 0;
        $offset = 0;
        $truncated = false;
        $jsonRows = [];
        $xlsx = $format === 'xlsx' ? app(XlsxWriter::class) : null;
        $options = $export->export_options ?? [];

        if ($format === 'csv') {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        do {
            if ($this->cancellationRequested($export)) {
                return ['rows' => $written, 'truncated' => false, 'cancelled' => true];
            }

            $page = $runner->page(
                $fusion,
                $tool,
                $query,
                $this->tenantKey,
                $offset,
                QueryExport::PAGE_SIZE,
            );
            $rows = $page['items'];

            if ($columns === null) {
                $columns = $runner->columns($query, $rows);

                match ($format) {
                    'csv' => fputcsv($handle, $columns, escape: ''),
                    'xlsx' => $xlsx->writeHeader($handle, $columns, $options),
                    default => null,
                };
            }

            foreach ($rows as $row) {
                if ($written >= $export->max_rows) {
                    $truncated = true;

                    break;
                }

                match ($format) {
                    'csv' => fputcsv($handle, $this->line($columns, $row), escape: ''),
                    'xlsx' => $xlsx->writeDataRow($handle, $row, $columns),
                    'json' => $jsonRows[] = $row,
                    default => null,
                };

                $written++;
            }

            if ($written >= $export->max_rows) {
                $truncated = $truncated || $page['hasMore'] || count($rows) > 0;

                break;
            }

            $offset += QueryExport::PAGE_SIZE;
            $export->forceFill(['row_count' => $written])->save();
        } while ($page['hasMore']);

        match ($format) {
            'xlsx' => $columns !== null ? $xlsx->writeFooter($handle) : null,
            'json' => fwrite($handle, json_encode($jsonRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            default => null,
        };

        return ['rows' => $written, 'truncated' => $truncated, 'cancelled' => false];
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function line(array $columns, array $row): array
    {
        return array_map(
            fn (string $column): string => $this->scalar($row[$column] ?? null),
            $columns,
        );
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
    }

    private function cancellationRequested(QueryExport $export): bool
    {
        return QueryExport::query()
            ->whereKey($export->id)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    private function markCancelled(
        QueryExport $export,
        User $user,
        Query $query,
        AuditRecorder $audit,
    ): void {
        $export->forceFill([
            'status' => QueryExportStatus::Cancelled,
            'cancel_requested_at' => $export->cancel_requested_at ?? now(),
            'file_path' => null,
            'finished_at' => now(),
        ])->save();

        $audit->record($user, 'query.export_cancelled', $query, [
            'query_export_id' => $export->id,
            'tenant_key' => $this->tenantKey,
        ]);
    }

    private function markFailed(
        QueryExport $export,
        User $user,
        Query $query,
        AuditRecorder $audit,
    ): void {
        $export->forceFill([
            'status' => QueryExportStatus::Failed,
            'error_code' => 'export_error',
            'file_path' => null,
            'finished_at' => now(),
        ])->save();

        $audit->record($user, 'query.export_failed', $query, [
            'query_export_id' => $export->id,
            'tenant_key' => $this->tenantKey,
            'error_code' => 'export_error',
        ]);
    }
}
