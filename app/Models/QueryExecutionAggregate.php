<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Daily aggregate of successful query runs (lot 10C).
 *
 * One row per query per calendar day (UTC). Updated in place while the day is
 * open; untouched once the day has closed. The values reflect succeeded runs
 * only — failed executions are excluded so the trend shows Oracle data quality,
 * not operational noise.
 *
 * @property int $id
 * @property int $query_id
 * @property string $period_date ISO-8601 date string (Y-m-d)
 * @property int $run_count
 * @property int $rows_min
 * @property int $rows_max
 * @property int $duration_min_ms
 * @property int $duration_max_ms
 * @property int $duration_avg_ms
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Query $ownedQuery
 */
#[Fillable([
    'query_id',
    'period_date',
    'run_count',
    'rows_min',
    'rows_max',
    'duration_min_ms',
    'duration_max_ms',
    'duration_avg_ms',
    'last_run_at',
])]
class QueryExecutionAggregate extends Model
{
    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'run_count' => 'integer',
            'rows_min' => 'integer',
            'rows_max' => 'integer',
            'duration_min_ms' => 'integer',
            'duration_max_ms' => 'integer',
            'duration_avg_ms' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * `query()` is reserved by Eloquent's static builder — use an explicit name.
     *
     * @return BelongsTo<Query, $this>
     */
    public function ownedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class);
    }
}
