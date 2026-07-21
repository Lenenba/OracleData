<?php

namespace App\Models;

use App\Enums\QueryExportStatus;
use Database\Factories\QueryExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mutable, per-user lifecycle of one asynchronous server-side query export.
 *
 * The in-flight state (status, progress, cancellation) lives here and the file
 * is streamed to the private disk. The tenant and connection are resolved in
 * the executor's own scope, never the query author's.
 *
 * @property int $id
 * @property int $user_id
 * @property int $query_id
 * @property int|null $oracle_tenant_id
 * @property int|null $auth_connection_id
 * @property QueryExportStatus $status
 * @property 'csv'|'xlsx'|'json' $format
 * @property array<string, mixed>|null $export_options
 * @property int $row_count
 * @property int $max_rows
 * @property bool $truncated
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $error_code
 * @property Carbon|null $cancel_requested_at
 * @property Carbon $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Query $executedQuery
 * @property-read OracleTenant|null $oracleTenant
 * @property-read AuthConnection|null $authConnection
 */
#[Fillable([
    'user_id',
    'query_id',
    'oracle_tenant_id',
    'auth_connection_id',
    'status',
    'format',
    'export_options',
    'row_count',
    'max_rows',
    'truncated',
    'file_path',
    'file_size',
    'error_code',
    'cancel_requested_at',
    'queued_at',
    'started_at',
    'finished_at',
    'expires_at',
])]
class QueryExport extends Model
{
    /** @use HasFactory<QueryExportFactory> */
    use HasFactory;

    /** Disk holding generated export files; never publicly served. */
    public const string DISK = 'local';

    /** Upper bound on rows pulled from Oracle for one export. */
    public const int MAX_ROWS = 50000;

    /** Rows fetched per Oracle page while streaming the file. */
    public const int PAGE_SIZE = 1000;

    /** Days a completed export file is retained before purge. */
    public const int RETENTION_DAYS = 7;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QueryExportStatus::class,
            'export_options' => 'array',
            'row_count' => 'integer',
            'max_rows' => 'integer',
            'truncated' => 'boolean',
            'file_size' => 'integer',
            'cancel_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * A completed export whose file is still present and unexpired.
     */
    public function isDownloadable(): bool
    {
        return $this->status === QueryExportStatus::Completed
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `query()` is reserved by Eloquent, so the relation carries an explicit name.
     *
     * @return BelongsTo<Query, $this>
     */
    public function executedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<OracleTenant, $this> */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /** @return BelongsTo<AuthConnection, $this> */
    public function authConnection(): BelongsTo
    {
        return $this->belongsTo(AuthConnection::class);
    }
}
