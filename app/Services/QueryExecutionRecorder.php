<?php

namespace App\Services;

use App\Models\OracleTenant;
use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Journalise chaque exécution de requête et maintient atomiquement les
 * agrégats dénormalisés de `queries`.
 *
 * Le tenant et la connexion enregistrés sont résolus dans le périmètre de
 * l'exécutant : pour une requête partagée, jamais ceux de l'auteur.
 */
class QueryExecutionRecorder
{
    public function __construct(private readonly SemanticLineageService $lineage) {}

    /**
     * @param  array<string, mixed>  $payload  Réponse normalisée de run() : `error` null = succès.
     */
    public function recordQueryRun(
        User $user,
        Query $query,
        string $tenantKey,
        array $payload,
        CarbonInterface $startedAt,
        int $durationMs,
    ): QueryExecution {
        $succeeded = ($payload['error'] ?? null) === null;
        $finishedAt = now();

        return DB::transaction(function () use ($user, $query, $tenantKey, $succeeded, $durationMs, $payload, $startedAt, $finishedAt): QueryExecution {
            // A run that started before a concurrent soft archive still
            // belongs to that historical query and must keep its provenance.
            $recordedQuery = Query::withTrashed()
                ->select(['id', 'query_template_version_id'])
                ->whereKey($query->id)
                ->lockForUpdate()
                ->first();
            $queryId = $recordedQuery?->id;
            $queryTemplateVersionId = $recordedQuery?->getAttribute('query_template_version_id');
            $tenant = $this->resolveTenant($user, $tenantKey);
            $semantic = $this->lineage->executionSnapshot($recordedQuery, null, $payload);

            $execution = QueryExecution::query()->create([
                'query_id' => $queryId,
                'query_template_id' => null,
                'query_template_version_id' => $queryTemplateVersionId,
                'semantic_catalog_version_id' => $semantic['catalog_version_id'],
                'semantic_lineage' => $semantic['lineage'] === [] ? null : $semantic['lineage'],
                'user_id' => $user->id,
                'oracle_tenant_id' => $tenant?->id,
                'auth_connection_id' => $tenant === null ? null : $this->primaryConnectionId($tenant),
                'source_type' => QueryExecution::SOURCE_SAVED_QUERY,
                'purpose' => QueryExecution::PURPOSE_RUN,
                'status' => $succeeded ? QueryExecution::STATUS_SUCCEEDED : QueryExecution::STATUS_FAILED,
                'duration_ms' => max(0, $durationMs),
                'rows_count' => count((array) ($payload['items'] ?? [])),
                'error_code' => $succeeded ? null : 'oracle_error',
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            if ($queryId !== null) {
                $aggregates = [
                    'execution_count' => DB::raw('execution_count + 1'),
                    'last_executed_at' => $finishedAt,
                ];

                if ($succeeded) {
                    $aggregates['successful_execution_count'] = DB::raw('successful_execution_count + 1');
                    $aggregates['last_successful_execution_at'] = $finishedAt;
                }

                Query::query()->whereKey($queryId)->update($aggregates);
            }

            return $execution;
        });
    }

    /**
     * Record a direct preview or run of a predefined template.
     *
     * Template actions never mutate the aggregates of a personal Query. A run
     * of a cloned template is recorded through recordQueryRun() instead.
     *
     * @param  array<string, mixed>  $payload  Normalized execution response.
     */
    public function recordTemplateAction(
        User $user,
        QueryTemplate $template,
        int $templateVersionId,
        string $tenantKey,
        array $payload,
        CarbonInterface $startedAt,
        int $durationMs,
        string $purpose,
    ): QueryExecution {
        if (! in_array($purpose, [
            QueryExecution::PURPOSE_RUN,
            QueryExecution::PURPOSE_PREVIEW,
            QueryExecution::PURPOSE_QUALITY_VALIDATION,
        ], true)) {
            throw new InvalidArgumentException("Unknown query-template execution purpose [{$purpose}].");
        }

        $succeeded = ($payload['error'] ?? null) === null;
        $finishedAt = now();

        return DB::transaction(function () use ($user, $template, $templateVersionId, $tenantKey, $payload, $startedAt, $durationMs, $purpose, $succeeded, $finishedAt): QueryExecution {
            $tenant = $this->resolveTenant($user, $tenantKey);
            $templateVersion = QueryTemplateVersion::query()->find($templateVersionId);
            $semantic = $this->lineage->executionSnapshot(null, $templateVersion, $payload);

            return QueryExecution::query()->create([
                'query_id' => null,
                'query_template_id' => $template->id,
                'query_template_version_id' => $templateVersionId,
                'semantic_catalog_version_id' => $semantic['catalog_version_id'],
                'semantic_lineage' => $semantic['lineage'] === [] ? null : $semantic['lineage'],
                'user_id' => $user->id,
                'oracle_tenant_id' => $tenant?->id,
                'auth_connection_id' => $tenant === null ? null : $this->primaryConnectionId($tenant),
                'source_type' => QueryExecution::SOURCE_QUERY_TEMPLATE,
                'purpose' => $purpose,
                'status' => $succeeded ? QueryExecution::STATUS_SUCCEEDED : QueryExecution::STATUS_FAILED,
                'duration_ms' => max(0, $durationMs),
                'rows_count' => count((array) ($payload['items'] ?? [])),
                'error_code' => $succeeded ? null : 'oracle_error',
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);
        });
    }

    /**
     * Backward-compatible alias while existing controllers migrate to the
     * explicit recordQueryRun() entry point.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        User $user,
        Query $query,
        string $tenantKey,
        array $payload,
        CarbonInterface $startedAt,
        int $durationMs,
    ): QueryExecution {
        return $this->recordQueryRun($user, $query, $tenantKey, $payload, $startedAt, $durationMs);
    }

    private function resolveTenant(User $user, string $tenantKey): ?OracleTenant
    {
        return $user->oracleTenants()
            ->where('key', $tenantKey)
            ->first();
    }

    private function primaryConnectionId(OracleTenant $tenant): ?int
    {
        return $tenant->authConnections()
            ->where('auth_type', 'basic')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');
    }
}
