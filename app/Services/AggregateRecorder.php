<?php

namespace App\Services;

use App\Models\QueryExecutionAggregate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lot 10C — upserts the daily execution aggregate for a personal query after
 * each successful run. This is intentionally a best-effort, non-blocking update:
 * if it fails it must not roll back the execution record.
 *
 * The aggregate is updated inside the same transaction as the execution record
 * (provided by the caller) to guarantee consistency without a separate round-trip.
 *
 * Only succeeded runs are aggregated; failed executions are excluded so the
 * trend chart reflects data quality, not operational noise.
 */
class AggregateRecorder
{
    /**
     * Update or create the daily aggregate row for the given query.
     *
     * @param  non-empty-string  $periodDate  ISO date string in UTC (Y-m-d).
     */
    public function record(
        int $queryId,
        string $periodDate,
        int $rowsCount,
        int $durationMs,
        Carbon $ranAt,
    ): void {
        $existing = QueryExecutionAggregate::query()
            ->where('query_id', $queryId)
            ->where('period_date', $periodDate)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            QueryExecutionAggregate::create([
                'query_id' => $queryId,
                'period_date' => $periodDate,
                'run_count' => 1,
                'rows_min' => $rowsCount,
                'rows_max' => $rowsCount,
                'duration_min_ms' => $durationMs,
                'duration_max_ms' => $durationMs,
                'duration_avg_ms' => $durationMs,
                'last_run_at' => $ranAt,
            ]);

            return;
        }

        // Recompute rolling average incrementally to avoid loading all runs.
        $newCount = $existing->run_count + 1;
        $newAvg = (int) round(
            ($existing->duration_avg_ms * $existing->run_count + $durationMs) / $newCount,
        );

        $existing->update([
            'run_count' => $newCount,
            'rows_min' => min($existing->rows_min, $rowsCount),
            'rows_max' => max($existing->rows_max, $rowsCount),
            'duration_min_ms' => min($existing->duration_min_ms, $durationMs),
            'duration_max_ms' => max($existing->duration_max_ms, $durationMs),
            'duration_avg_ms' => $newAvg,
            'last_run_at' => $ranAt,
        ]);
    }
}
