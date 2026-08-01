<?php

namespace App\Http\Controllers;

use App\Models\QueryDashboard;
use App\Models\QueryDashboardWidget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Lot 10B — CRUD for individual widgets on a dashboard.
 *
 * Widgets are lightweight: type (kpi|table|chart), a pointer to a query,
 * a position, and a JSON options bag (e.g. column to aggregate, limit).
 */
class QueryDashboardWidgetController extends Controller
{
    private const array ALLOWED_TYPES = ['kpi', 'table', 'chart'];

    /**
     * Add a widget to a dashboard.
     */
    public function store(Request $request, QueryDashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);

        $validated = $request->validate([
            'query_id' => ['required', 'integer', 'exists:queries,id'],
            'widget_type' => ['required', 'string', Rule::in(self::ALLOWED_TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
            'widget_options' => ['nullable', 'array'],
        ]);

        $position = $validated['position'] ?? ($dashboard->widgets()->max('position') + 1);

        $dashboard->widgets()->create([
            'query_id' => $validated['query_id'],
            'widget_type' => $validated['widget_type'],
            'title' => $validated['title'] ?? null,
            'position' => (int) $position,
            'widget_options' => $validated['widget_options'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Widget ajouté.')]);

        return to_route('dashboards.show', $dashboard);
    }

    /**
     * Update widget position / options.
     */
    public function update(Request $request, QueryDashboard $dashboard, QueryDashboardWidget $widget): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        abort_unless($widget->dashboard_id === $dashboard->id, 404);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
            'widget_options' => ['nullable', 'array'],
        ]);

        $widget->update(array_filter($validated, fn ($v): bool => $v !== null));

        return back();
    }

    /**
     * Remove a widget from the dashboard.
     */
    public function destroy(QueryDashboard $dashboard, QueryDashboardWidget $widget): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        abort_unless($widget->dashboard_id === $dashboard->id, 404);

        $widget->delete();

        return back();
    }

    /**
     * Reorder widgets in bulk (PATCH body: [{id, position}]).
     */
    public function reorder(Request $request, QueryDashboard $dashboard): JsonResponse
    {
        Gate::authorize('update', $dashboard);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.position' => ['required', 'integer', 'min:0'],
        ]);

        $widgetIds = $dashboard->widgets()->pluck('id')->flip()->all();

        foreach ($validated['order'] as $item) {
            if (! isset($widgetIds[$item['id']])) {
                continue;
            }

            QueryDashboardWidget::where('id', $item['id'])->update(['position' => $item['position']]);
        }

        return response()->json(['ok' => true]);
    }
}
