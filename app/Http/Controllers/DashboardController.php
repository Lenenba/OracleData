<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryExecution;
use App\Services\FusionManager;
use App\Services\OracleResourceCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class DashboardController extends Controller
{
    /**
     * Show the dashboard with KPIs, real activity series, recent queries
     * and tenant status.
     */
    public function __invoke(Request $request, FusionManager $fusion, OracleResourceCatalog $catalog): Response
    {
        $user = $request->user();
        $userId = $user->id;
        $groupIds = $user->groupIdsForQueryAccess();
        $fusion = $fusion->forUser($user);

        $accessible = fn (): Builder => Query::query()->accessibleTo($user);

        $overview = $this->queryOverview($accessible(), $userId);
        $usageStats = $this->usageStats($userId);

        $recentQueries = $accessible()
            ->select([
                'id',
                'user_id',
                'name',
                'description',
                'mode',
                'tenant_key',
                'access_level',
                'created_at',
            ])
            ->with([
                'user:id,name',
                'userShares' => fn ($share) => $share->where('user_id', $userId),
                'groupShares' => fn ($share) => $share
                    ->active()
                    ->whereIn('group_id', $groupIds),
            ])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Query $query): array => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'mode' => $query->mode,
                'tenant_label' => $query->user_id === $userId
                    ? $fusion->label($query->tenant_key)
                    : null,
                'access_level' => $query->access_level,
                'owner' => $query->user->name,
                'can' => [
                    'update' => $query->user_id === $userId,
                    'execute' => $user->can('execute', $query),
                    'clone' => $user->can('clone', $query),
                    'manage_sharing' => $user->can('manageSharing', $query),
                ],
            ]);

        $tenantDetails = $fusion->details();

        return Inertia::render('dashboard', [
            'stats' => [
                'totalQueries' => $overview['totalQueries'],
                'myQueries' => $overview['myQueries'],
                'sharedQueries' => $overview['sharedQueries'],
                'activeTenants' => collect($tenantDetails)->where('is_active', true)->count(),
                ...$usageStats,
            ],
            'queriesPerWeek' => $overview['queriesPerWeek'],
            'domainBreakdown' => $this->domainBreakdown($accessible(), $catalog),
            'recentQueries' => $recentQueries,
            'tenants' => $tenantDetails,
        ]);
    }

    /**
     * Agrège en une requête les compteurs de bibliothèque et les huit semaines
     * d'activité. Les buckets CASE sont portables et évitent de rapatrier les
     * dates pour les regrouper en PHP.
     *
     * @param  Builder<Query>  $accessible
     * @return array{
     *     totalQueries: int,
     *     myQueries: int,
     *     sharedQueries: int,
     *     queriesPerWeek: list<int>
     * }
     */
    protected function queryOverview(Builder $accessible, int $userId): array
    {
        $selects = [
            'COUNT(*) AS total_queries',
            'COALESCE(SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END), 0) AS my_queries',
            'COALESCE(SUM(CASE WHEN user_id <> ? THEN 1 ELSE 0 END), 0) AS shared_queries',
        ];
        $bindings = [$userId, $userId];
        $currentWeekStart = now()->startOfWeek();

        for ($index = 0; $index < 8; $index++) {
            $start = $currentWeekStart->copy()->subWeeks(7 - $index);
            $end = $start->copy()->addWeek();
            $selects[] = "COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS week_{$index}";
            $bindings[] = $start;
            $bindings[] = $end;
        }

        $values = (array) $accessible
            ->toBase()
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $weeks = [];

        for ($index = 0; $index < 8; $index++) {
            $weeks[] = (int) ($values["week_{$index}"] ?? 0);
        }

        return [
            'totalQueries' => (int) ($values['total_queries'] ?? 0),
            'myQueries' => (int) ($values['my_queries'] ?? 0),
            'sharedQueries' => (int) ($values['shared_queries'] ?? 0),
            'queriesPerWeek' => $weeks,
        ];
    }

    /**
     * Statistiques des exécutions réellement lancées par l'utilisateur pendant
     * le mois courant, indépendamment du propriétaire de la requête exécutée.
     *
     * @return array{executionsThisMonth: int, successRate: float|int, averageDurationMs: int}
     */
    protected function usageStats(int $userId): array
    {
        $monthStart = now()->startOfMonth();
        $nextMonth = $monthStart->copy()->addMonth();
        $values = (array) QueryExecution::query()
            ->where('user_id', $userId)
            ->where('purpose', QueryExecution::PURPOSE_RUN)
            ->where('finished_at', '>=', $monthStart)
            ->where('finished_at', '<', $nextMonth)
            ->toBase()
            ->selectRaw(
                'COUNT(*) AS execution_count, '
                .'COALESCE(100.0 * SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 0) AS success_rate, '
                .'COALESCE(AVG(duration_ms), 0) AS average_duration_ms',
                [QueryExecution::STATUS_SUCCEEDED],
            )
            ->first();

        return [
            'executionsThisMonth' => (int) ($values['execution_count'] ?? 0),
            'successRate' => round((float) ($values['success_rate'] ?? 0), 1),
            'averageDurationMs' => (int) round((float) ($values['average_duration_ms'] ?? 0)),
        ];
    }

    /**
     * Répartition des requêtes accessibles par domaine Oracle, déduite du
     * `resource_key` persisté et du catalogue ; « Autre » sinon.
     *
     * @param  Builder<Query>  $accessible
     * @return array<int, array{domain: string, count: int}>
     */
    protected function domainBreakdown(Builder $accessible, OracleResourceCatalog $catalog): array
    {
        $resourcesByDomain = array_reduce(
            $catalog->all(),
            function (array $map, array $resource): array {
                $map[$resource['domain']][] = $resource['key'];

                return $map;
            },
            [],
        );

        if ($resourcesByDomain === []) {
            return [];
        }

        $resourceKey = $this->resourceKeyExpression($accessible);
        $cases = [];
        $bindings = [];

        foreach ($resourcesByDomain as $domain => $resourceKeys) {
            $placeholders = implode(', ', array_fill(0, count($resourceKeys), '?'));
            $cases[] = "WHEN {$resourceKey} IN ({$placeholders}) THEN ?";
            array_push($bindings, ...$resourceKeys);
            $bindings[] = $domain;
        }

        $domainExpression = 'CASE '.implode(' ', $cases).' ELSE ? END';
        $bindings[] = 'Autre';

        return $accessible
            ->toBase()
            ->selectRaw("{$domainExpression} AS resource_domain, COUNT(*) AS aggregate", $bindings)
            ->groupBy('resource_domain')
            ->orderByDesc('aggregate')
            ->orderBy('resource_domain')
            ->get()
            ->map(function (object $row): array {
                $values = (array) $row;

                return [
                    'domain' => (string) $values['resource_domain'],
                    'count' => (int) $values['aggregate'],
                ];
            })
            ->all();
    }

    /**
     * Expression d'extraction JSON propre au moteur courant. Le reste de
     * l'agrégation demeure identique sur SQLite, MySQL/MariaDB et PostgreSQL.
     *
     * @param  Builder<Query>  $query
     * @return literal-string
     */
    protected function resourceKeyExpression(Builder $query): string
    {
        $connection = $query->getModel()->getConnection();

        return match ($connection->getDriverName()) {
            'sqlite' => "json_extract(parameters, '$.resource_key')",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(parameters, '$.resource_key'))",
            'pgsql' => "parameters->>'resource_key'",
            default => throw new RuntimeException('Unsupported database driver for dashboard domain aggregation.'),
        };
    }
}
