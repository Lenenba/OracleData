<?php

namespace App\Models;

use Database\Factories\QueryAlertEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable record of one alert firing on a specific scheduled run.
 *
 * @property int $id
 * @property int $query_alert_id
 * @property int $query_schedule_run_id
 * @property int $user_id
 * @property int|null $observed_value
 * @property Carbon $triggered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryAlert $alert
 * @property-read QueryScheduleRun $scheduleRun
 * @property-read User $user
 */
#[Fillable([
    'query_alert_id',
    'query_schedule_run_id',
    'user_id',
    'observed_value',
    'triggered_at',
])]
class QueryAlertEvent extends Model
{
    /** @use HasFactory<QueryAlertEventFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observed_value' => 'integer',
            'triggered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QueryAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(QueryAlert::class, 'query_alert_id');
    }

    /** @return BelongsTo<QueryScheduleRun, $this> */
    public function scheduleRun(): BelongsTo
    {
        return $this->belongsTo(QueryScheduleRun::class, 'query_schedule_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
