<?php

namespace App\Models;

use App\Enums\AlertCondition;
use Database\Factories\QueryAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user-owned condition evaluated against each run of one schedule.
 *
 * @property int $id
 * @property int $query_schedule_id
 * @property int $user_id
 * @property string $name
 * @property AlertCondition $condition
 * @property int|null $threshold
 * @property bool $is_active
 * @property Carbon|null $last_triggered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QuerySchedule $schedule
 * @property-read User $user
 * @property-read Collection<int, QueryAlertEvent> $events
 */
#[Fillable([
    'query_schedule_id',
    'user_id',
    'name',
    'condition',
    'threshold',
    'is_active',
    'last_triggered_at',
])]
class QueryAlert extends Model
{
    /** @use HasFactory<QueryAlertFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'condition' => AlertCondition::class,
            'threshold' => 'integer',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
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

    /** @return HasMany<QueryAlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(QueryAlertEvent::class);
    }
}
