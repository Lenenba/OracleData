<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Services\FusionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard with KPIs, recent queries and tenant status.
     */
    public function __invoke(Request $request, FusionManager $fusion): Response
    {
        $userId = $request->user()->id;

        $recentQueries = Query::query()
            ->where(fn (Builder $q) => $q
                ->where('user_id', $userId)
                ->orWhere('visibility', 'shared'))
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
                'totalQueries' => Query::query()
                    ->where(fn (Builder $q) => $q
                        ->where('user_id', $userId)
                        ->orWhere('visibility', 'shared'))
                    ->count(),
                'myQueries' => Query::query()->where('user_id', $userId)->count(),
                'activeTenants' => count($tenantDetails),
            ],
            'recentQueries' => $recentQueries,
            'tenants' => $tenantDetails,
        ]);
    }
}
