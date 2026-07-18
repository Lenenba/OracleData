<?php

namespace App\Services;

use App\Models\OracleTenant;
use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Journalise chaque exécution de requête et maintient atomiquement les
 * agrégats dénormalisés de `queries`.
 *
 * Le tenant et la connexion enregistrés sont résolus dans le périmètre de
 * l'exécutant : pour une requête partagée, jamais ceux de l'auteur.
 */
class QueryExecutionRecorder
{
    /**
     * @param  array<string, mixed>  $payload  Réponse normalisée de run() : `error` null = succès.
     */
    public function record(
        User $user,
        Query $query,
        string $tenantKey,
        array $payload,
        CarbonInterface $startedAt,
        int $durationMs,
    ): QueryExecution {
        $succeeded = ($payload['error'] ?? null) === null;
        $finishedAt = now();
        $tenant = $this->resolveTenant($user, $tenantKey);

        $execution = QueryExecution::query()->create([
            'query_id' => $query->id,
            'user_id' => $user->id,
            'oracle_tenant_id' => $tenant?->id,
            'auth_connection_id' => $tenant === null ? null : $this->primaryConnectionId($tenant),
            'status' => $succeeded ? QueryExecution::STATUS_SUCCEEDED : QueryExecution::STATUS_FAILED,
            'duration_ms' => max(0, $durationMs),
            'rows_count' => count((array) ($payload['items'] ?? [])),
            'error_code' => $succeeded ? null : 'oracle_error',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $aggregates = [
            'execution_count' => DB::raw('execution_count + 1'),
            'last_executed_at' => $finishedAt,
        ];

        if ($succeeded) {
            $aggregates['successful_execution_count'] = DB::raw('successful_execution_count + 1');
            $aggregates['last_successful_execution_at'] = $finishedAt;
        }

        Query::query()->whereKey($query->id)->update($aggregates);

        return $execution;
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
