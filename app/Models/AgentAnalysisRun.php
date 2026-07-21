<?php

namespace App\Models;

use App\Enums\AgentAnalysisRunStatus;
use Database\Factories\AgentAnalysisRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mutable, per-user lifecycle of one asynchronous agent analysis.
 *
 * The in-flight state (status, progress, cancellation) lives here; the
 * immutable outcome is written to `query_executions` at completion. The
 * tenant and connection are resolved in the executor's own scope, never the
 * query author's.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $query_id
 * @property int|null $oracle_tenant_id
 * @property int|null $auth_connection_id
 * @property int|null $query_execution_id
 * @property AgentAnalysisRunStatus $status
 * @property int $iteration
 * @property int $max_iterations
 * @property int $oracle_calls_count
 * @property array<string, mixed>|null $result
 * @property int $row_count
 * @property string|null $error_code
 * @property Carbon|null $cancel_requested_at
 * @property Carbon $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Query|null $executedQuery
 * @property-read OracleTenant|null $oracleTenant
 * @property-read AuthConnection|null $authConnection
 * @property-read QueryExecution|null $queryExecution
 */
#[Fillable([
    'user_id',
    'query_id',
    'oracle_tenant_id',
    'auth_connection_id',
    'query_execution_id',
    'status',
    'iteration',
    'max_iterations',
    'oracle_calls_count',
    'result',
    'row_count',
    'error_code',
    'cancel_requested_at',
    'queued_at',
    'started_at',
    'finished_at',
])]
class AgentAnalysisRun extends Model
{
    /** @use HasFactory<AgentAnalysisRunFactory> */
    use HasFactory;

    /**
     * Upper bound on rows persisted in `result`, protecting the row from an
     * unexpectedly large agent output. `row_count` still reflects the real total.
     */
    public const int MAX_RESULT_ROWS = 2000;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AgentAnalysisRunStatus::class,
            'result' => 'array',
            'iteration' => 'integer',
            'max_iterations' => 'integer',
            'oracle_calls_count' => 'integer',
            'row_count' => 'integer',
            'cancel_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * A run whose cancellation was requested before it reached a terminal state.
     */
    public function isCancellationRequested(): bool
    {
        return $this->cancel_requested_at !== null;
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

    /** @return BelongsTo<QueryExecution, $this> */
    public function queryExecution(): BelongsTo
    {
        return $this->belongsTo(QueryExecution::class);
    }
}
