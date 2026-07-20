<?php

namespace App\Models;

use Database\Factories\QueryScheduleRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Timestamped outcome of one unattended execution of a scheduled query.
 * Kept out of `query_executions` so scheduled runs never skew dashboard stats.
 *
 * @property int $id
 * @property int $query_schedule_id
 * @property int $user_id
 * @property int|null $oracle_tenant_id
 * @property int|null $auth_connection_id
 * @property string $status
 * @property int $row_count
 * @property int $duration_ms
 * @property string|null $error_code
 * @property Carbon $ran_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QuerySchedule $schedule
 * @property-read User $user
 */
#[Fillable([
    'query_schedule_id',
    'user_id',
    'oracle_tenant_id',
    'auth_connection_id',
    'status',
    'row_count',
    'duration_ms',
    'error_code',
    'ran_at',
])]
class QueryScheduleRun extends Model
{
    /** @use HasFactory<QueryScheduleRunFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'duration_ms' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QuerySchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(QuerySchedule::class, 'query_schedule_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
