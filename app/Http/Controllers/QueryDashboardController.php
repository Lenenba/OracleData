<?php

namespace App\Http\Controllers;

use App\Models\QueryDashboard;
use App\Models\QueryDashboardWidget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lot 10B — composable dashboards.
 *
 * Each authenticated user can create and manage personal dashboards that
 * aggregate results from their accessible queries via typed widgets
 * (KPI scalar, table, bar/line chart).
 */
class QueryDashboardController extends Controller
{
    /**
     * List all dashboards owned by the current user.
     */
    public function index(Request $request): Response
    {
        $dashboards = QueryDashboard::query()
            ->where('user_id', $request->user()->id)
            ->withCount('widgets')
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'description', 'created_at', 'updated_at']);

        return Inertia::render('dashboards/index', [
            'dashboards' => $dashboards->map(fn (QueryDashboard $d): array => [
                'id'          => $d->id,
                'name'        => $d->name,
                'description' => $d->description,
                'widget_count' => (int) $d->widgets_count,
                'updated_at'  => $d->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Show the form for creating a new dashboard.
     */
    public function create(): Response
    {
        return Inertia::render('dashboards/create');
    }

    /**
     * Store a newly created dashboard.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $dashboard = QueryDashboard::create([
            'user_id'     => $request->user()->id,
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tableau de bord créé.')]);

        return to_route('dashboards.show', $dashboard);
    }

    /**
     * Show the dashboard and its widgets.
     */
    public function show(Request $request, QueryDashboard $dashboard): Response
    {
        Gate::authorize('view', $dashboard);

        $user = $request->user();

        $dashboard->load([
            'widgets.sourceQuery',
        ]);

        // All queries owned by the user (for the add-widget picker).
        $userQueries = \App\Models\Query::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'resource_path'])
            ->map(fn (\App\Models\Query $q): array => [
                'id'            => $q->id,
                'name'          => $q->name,
                'resource_path' => $q->resource_path,
            ]);

        return Inertia::render('dashboards/show', [
            'dashboard' => [
                'id'          => $dashboard->id,
                'name'        => $dashboard->name,
                'description' => $dashboard->description,
                'widgets'     => $dashboard->widgets->map(fn (QueryDashboardWidget $w): array => [
                    'id'             => $w->id,
                    'widget_type'    => $w->widget_type,
                    'title'          => $w->title,
                    'position'       => $w->position,
                    'widget_options' => $w->widget_options ?? [],
                    'query'          => [
                        'id'            => $w->sourceQuery->id,
                        'name'          => $w->sourceQuery->name,
                        'resource_path' => $w->sourceQuery->resource_path,
                    ],
                ]),
            ],
            'userQueries' => $userQueries,
        ]);
    }

    /**
     * Show the edit form for a dashboard.
     */
    public function edit(QueryDashboard $dashboard): Response
    {
        Gate::authorize('update', $dashboard);

        return Inertia::render('dashboards/edit', [
            'dashboard' => [
                'id'          => $dashboard->id,
                'name'        => $dashboard->name,
                'description' => $dashboard->description,
            ],
        ]);
    }

    /**
     * Update dashboard metadata.
     */
    public function update(Request $request, QueryDashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $dashboard->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tableau de bord mis à jour.')]);

        return to_route('dashboards.show', $dashboard);
    }

    /**
     * Delete a dashboard (and all its widgets via cascade).
     */
    public function destroy(QueryDashboard $dashboard): RedirectResponse
    {
        Gate::authorize('delete', $dashboard);

        $dashboard->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tableau de bord supprimé.')]);

        return to_route('dashboards.index');
    }
}
