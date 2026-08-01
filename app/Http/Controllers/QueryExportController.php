<?php

namespace App\Http\Controllers;

use App\Enums\QueryExportStatus;
use App\Http\Requests\StoreQueryExportRequest;
use App\Jobs\RunQueryExport;
use App\Models\Query;
use App\Models\QueryExport;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generates a full CSV export of a saved query off the request cycle. The
 * definition may be shared, but the export always re-executes with an active
 * Oracle connection belonging to the reader; a file is only ever readable,
 * cancellable or downloadable by its own owner.
 */
class QueryExportController extends Controller
{
    /**
     * Queue a server-side CSV export for a saved non-agent query.
     */
    public function store(
        StoreQueryExportRequest $request,
        Query $query,
        FusionManager $fusion,
        AuditRecorder $audit,
    ): JsonResponse {
        Gate::authorize('execute', $query);

        abort_if(
            $query->mode === 'agent',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Les analyses agent ne s’exportent pas côté serveur.'),
        );

        $fusion = $fusion->forUser($request->user());
        $ownerPreferred = $query->user_id === $request->user()->id && $fusion->has($query->tenant_key)
            ? $query->tenant_key
            : null;
        $tenantKey = (string) ($request->validated()['tenant'] ?? $ownerPreferred ?? $fusion->defaultKey());

        abort_unless(
            $fusion->has($tenantKey),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Configurez une connexion Oracle active avant de lancer un export.'),
        );

        $tenant = $request->user()->oracleTenants()->where('key', $tenantKey)->first();

        $format = (string) ($request->validated()['format'] ?? 'csv');
        $exportOptions = is_array($request->validated()['export_options'] ?? null)
            ? $request->validated()['export_options']
            : null;

        $export = QueryExport::create([
            'user_id' => $request->user()->id,
            'query_id' => $query->id,
            'oracle_tenant_id' => $tenant?->id,
            'status' => QueryExportStatus::Queued,
            'format' => $format,
            'export_options' => $exportOptions,
            'max_rows' => QueryExport::MAX_ROWS,
            'queued_at' => now(),
        ]);

        RunQueryExport::dispatch($export, $tenantKey);

        $audit->record($request->user(), 'query.export_dispatched', $query, [
            'query_export_id' => $export->id,
            'tenant_key' => $tenantKey,
        ]);

        return response()->json($this->toStatusPayload($export), Response::HTTP_ACCEPTED);
    }

    /**
     * Return the current state of an export so the owner can poll its progress.
     */
    public function show(Request $request, QueryExport $queryExport): JsonResponse
    {
        abort_unless(
            $queryExport->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        return response()->json($this->toStatusPayload($queryExport));
    }

    /**
     * Request cancellation. A queued export is resolved immediately; a running
     * one is stopped cooperatively by the job between two pages.
     */
    public function cancel(
        Request $request,
        QueryExport $queryExport,
        AuditRecorder $audit,
    ): JsonResponse {
        abort_unless(
            $queryExport->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        if ($queryExport->status->isTerminal()) {
            return response()->json($this->toStatusPayload($queryExport));
        }

        if ($queryExport->status === QueryExportStatus::Queued) {
            $queryExport->forceFill([
                'status' => QueryExportStatus::Cancelled,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
            ])->save();

            $audit->record($request->user(), 'query.export_cancelled', $queryExport->executedQuery, [
                'query_export_id' => $queryExport->id,
            ]);
        } else {
            $queryExport->forceFill(['cancel_requested_at' => now()])->save();
        }

        return response()->json($this->toStatusPayload($queryExport));
    }

    /**
     * Stream the generated file to its owner while it exists and is unexpired.
     * The MIME type and suggested filename derive from the export format.
     */
    public function download(Request $request, QueryExport $queryExport): StreamedResponse
    {
        abort_unless(
            $queryExport->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $disk = Storage::disk(QueryExport::DISK);

        abort_unless(
            $queryExport->isDownloadable() && $disk->exists((string) $queryExport->file_path),
            Response::HTTP_NOT_FOUND,
        );

        $format = $queryExport->format;

        [$mime, $extension] = match ($format) {
            'xlsx' => ['application/vnd.ms-excel', 'xls'],
            'json' => ['application/json', 'json'],
            default => ['text/csv', 'csv'],
        };

        return $disk->download(
            (string) $queryExport->file_path,
            'export-'.$queryExport->id.'.'.$extension,
            ['Content-Type' => $mime],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toStatusPayload(QueryExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status->value,
            'row_count' => $export->row_count,
            'truncated' => $export->truncated,
            'error_code' => $export->error_code,
            'downloadable' => $export->isDownloadable(),
            'queued_at' => $export->queued_at->toIso8601String(),
            'started_at' => $export->started_at?->toIso8601String(),
            'finished_at' => $export->finished_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
