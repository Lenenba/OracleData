<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryExecutionAggregate;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lot 10C — returns the recent daily execution aggregates for a personal
 * query so the show page can render the temporal comparison trend panel.
 *
 * The endpoint is lightweight (indexed read, no Oracle call) and can therefore
 * tolerate a generous throttle that absorbs page refreshes.
 */
class QueryAggregateController extends Controller
{
    /**
     * Return the last 90 days of daily aggregates for the given query.
     * Sparse: only days that had at least one successful run are returned.
     */
    public function index(Request $request, Query $query): JsonResponse
    {
        Gate::authorize('view', $query);

        $rows = QueryExecutionAggregate::query()
            ->where('query_id', $query->id)
            ->where('period_date', '>=', now()->subDays(90)->toDateString())
            ->orderBy('period_date')
            ->get(['period_date', 'run_count', 'rows_min', 'rows_max', 'duration_min_ms', 'duration_max_ms', 'duration_avg_ms', 'last_run_at'])
            ->map(fn (QueryExecutionAggregate $row): array => [
                'date' => $this->dateString($row->period_date),
                'run_count' => $row->run_count,
                'rows_min' => $row->rows_min,
                'rows_max' => $row->rows_max,
                'duration_min_ms' => $row->duration_min_ms,
                'duration_max_ms' => $row->duration_max_ms,
                'duration_avg_ms' => $row->duration_avg_ms,
                'last_run_at' => $row->last_run_at?->toIso8601String(),
            ]);

        return response()->json($rows);
    }

    private function dateString(CarbonInterface|string $value): string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }
}
