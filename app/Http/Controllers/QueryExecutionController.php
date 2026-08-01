<?php

namespace App\Http\Controllers;

use App\Models\QueryExecution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Observability layer — browse and inspect every query execution
 * performed by the authenticated user, with full details per run.
 */
class QueryExecutionController extends Controller
{
    private const PAGE_SIZE = 30;

    /**
     * Paginated list of the user's executions, filterable by status/source/query.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $status = $request->string('status')->toString();
        $source = $request->string('source')->toString();
        $queryId = $request->integer('query_id') ?: null;
        $tenantId = $request->integer('tenant_id') ?: null;

        $executions = QueryExecution::query()
            ->where('user_id', $user->id)
            ->with([
                'executedQuery:id,name,resource_path',
                'oracleTenant:id,label,key',
            ])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when(in_array($source, ['saved_query', 'query_template'], true), fn ($q) => $q->where('source_type', $source))
            ->when($queryId !== null, fn ($q) => $q->where('query_id', $queryId))
            ->when($tenantId !== null, fn ($q) => $q->where('oracle_tenant_id', $tenantId))
            ->orderByDesc('started_at')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString()
            ->through(fn (QueryExecution $e): array => [
                'id' => $e->id,
                'status' => $e->status,
                'source_type' => $e->source_type,
                'purpose' => $e->purpose,
                'duration_ms' => $e->duration_ms,
                'rows_count' => $e->rows_count,
                'error_code' => $e->error_code,
                'started_at' => $e->started_at->toIso8601String(),
                'finished_at' => $e->finished_at->toIso8601String(),
                'query' => $e->executedQuery ? [
                    'id' => $e->executedQuery->id,
                    'name' => $e->executedQuery->name,
                    'resource_path' => $e->executedQuery->resource_path,
                ] : null,
                'tenant' => $e->oracleTenant ? [
                    'id' => $e->oracleTenant->id,
                    'label' => $e->oracleTenant->label,
                    'key' => $e->oracleTenant->key,
                ] : null,
            ]);

        // KPI counters for the period visible (last 30 days, user's own executions).
        $since = now()->subDays(30)->toDateTimeString();
        $stats = QueryExecution::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as succeeded,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                COALESCE(AVG(CASE WHEN status = ? THEN duration_ms END), 0) as avg_ms,
                COALESCE(AVG(CASE WHEN status = ? THEN rows_count END), 0) as avg_rows
            ', [
                QueryExecution::STATUS_SUCCEEDED,
                QueryExecution::STATUS_FAILED,
                QueryExecution::STATUS_SUCCEEDED,
                QueryExecution::STATUS_SUCCEEDED,
            ])
            ->first();

        // Distinct tenants the user has used, for the tenant filter dropdown.
        $tenants = $user->oracleTenants()
            ->orderBy('label')
            ->get(['id', 'label', 'key'])
            ->map(fn ($t) => ['id' => $t->id, 'label' => $t->label, 'key' => $t->key]);

        return Inertia::render('executions/index', [
            'executions' => $executions,
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'succeeded' => (int) ($stats->succeeded ?? 0),
                'failed' => (int) ($stats->failed ?? 0),
                'avg_ms' => (int) round($stats->avg_ms ?? 0),
                'avg_rows' => (int) round($stats->avg_rows ?? 0),
            ],
            'tenants' => $tenants,
            'filters' => [
                'status' => $status,
                'source' => $source,
                'query_id' => $queryId,
                'tenant_id' => $tenantId,
            ],
        ]);
    }

    /**
     * Full detail for a single execution (fields, timing, error, tenant info).
     */
    public function show(Request $request, QueryExecution $queryExecution): Response
    {
        abort_unless(
            $queryExecution->user_id === (int) $request->user()->id,
            403,
        );

        $queryExecution->loadMissing([
            'executedQuery:id,name,resource_path,mode',
            'queryTemplate:id,identifier,title',
            'oracleTenant:id,label,key,type',
        ]);

        return Inertia::render('executions/show', [
            'execution' => [
                'id' => $queryExecution->id,
                'status' => $queryExecution->status,
                'source_type' => $queryExecution->source_type,
                'purpose' => $queryExecution->purpose,
                'duration_ms' => $queryExecution->duration_ms,
                'rows_count' => $queryExecution->rows_count,
                'error_code' => $queryExecution->error_code,
                'started_at' => $queryExecution->started_at->toIso8601String(),
                'finished_at' => $queryExecution->finished_at->toIso8601String(),
                'query' => $queryExecution->executedQuery ? [
                    'id' => $queryExecution->executedQuery->id,
                    'name' => $queryExecution->executedQuery->name,
                    'resource_path' => $queryExecution->executedQuery->resource_path,
                    'mode' => $queryExecution->executedQuery->mode,
                ] : null,
                'template' => $queryExecution->queryTemplate ? [
                    'id' => $queryExecution->queryTemplate->id,
                    'identifier' => $queryExecution->queryTemplate->slug,
                    'title' => $queryExecution->queryTemplate->name,
                ] : null,
                'tenant' => $queryExecution->oracleTenant ? [
                    'id' => $queryExecution->oracleTenant->id,
                    'label' => $queryExecution->oracleTenant->label,
                    'key' => $queryExecution->oracleTenant->key,
                    'type' => $queryExecution->oracleTenant->type,
                ] : null,
            ],
        ]);
    }
}
