<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Services\FusionManager;
use App\Services\OracleResourceCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard with KPIs, real activity series, recent queries
     * and tenant status.
     */
    public function __invoke(Request $request, FusionManager $fusion, OracleResourceCatalog $catalog): Response
    {
        $userId = $request->user()->id;

        $accessible = fn (): Builder => Query::query()
            ->where(fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->orWhere('visibility', 'shared'));

        $recentQueries = $accessible()
            ->with('user:id,name')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Query $query): array => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'mode' => $query->mode,
                'tenant_label' => $fusion->label($query->tenant_key),
                'visibility' => $query->visibility,
                'owner' => $query->user->name,
                'can' => ['update' => $query->user_id === $userId],
            ]);

        $tenantDetails = $fusion->details();

        return Inertia::render('dashboard', [
            'stats' => [
                'totalQueries' => $accessible()->count(),
                'myQueries' => Query::query()->where('user_id', $userId)->count(),
                'sharedQueries' => $accessible()->where('visibility', 'shared')->count(),
                'activeTenants' => count($tenantDetails),
            ],
            'queriesPerWeek' => $this->queriesPerWeek($accessible()),
            'domainBreakdown' => $this->domainBreakdown($accessible(), $catalog),
            'recentQueries' => $recentQueries,
            'tenants' => $tenantDetails,
        ]);
    }

    /**
     * Nombre de requêtes créées par semaine sur les 8 dernières semaines
     * (la plus ancienne d'abord, la semaine courante en dernier).
     *
     * @return list<int>
     */
    protected function queriesPerWeek(Builder $accessible): array
    {
        $createdAt = $accessible
            ->where('created_at', '>=', now()->startOfWeek()->subWeeks(7))
            ->pluck('created_at');

        $weeks = [];

        for ($i = 7; $i >= 0; $i--) {
            $start = now()->startOfWeek()->subWeeks($i);
            $end = $start->copy()->addWeek();

            $weeks[] = $createdAt
                ->filter(fn ($date) => $date !== null && $date->gte($start) && $date->lt($end))
                ->count();
        }

        return $weeks;
    }

    /**
     * Répartition des requêtes accessibles par domaine Oracle, déduite du
     * `resource_key` persisté et du catalogue ; « Autre » sinon.
     *
     * @return list<array{domain: string, count: int}>
     */
    protected function domainBreakdown(Builder $accessible, OracleResourceCatalog $catalog): array
    {
        $domains = array_reduce(
            $catalog->all(),
            function (array $map, array $resource): array {
                $map[$resource['key']] = $resource['domain'];

                return $map;
            },
            [],
        );

        return $accessible
            ->pluck('parameters')
            ->map(fn ($parameters): string => $domains[$parameters['resource_key'] ?? null] ?? 'Autre')
            ->countBy()
            ->map(fn (int $count, string $domain): array => ['domain' => $domain, 'count' => $count])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
