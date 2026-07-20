<?php

namespace App\Models;

use App\Enums\ScheduleFrequency;
use Database\Factories\QueryScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring, unattended execution of one saved query, owned by a user and
 * always run in that owner's own connection scope.
 *
 * @property int $id
 * @property int $user_id
 * @property int $query_id
 * @property int|null $oracle_tenant_id
 * @property string $name
 * @property string $tenant_key
 * @property ScheduleFrequency $frequency
 * @property string|null $time_of_day
 * @property int|null $day_of_week
 * @property string $timezone
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property string|null $last_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Query $executedQuery
 * @property-read OracleTenant|null $oracleTenant
 * @property-read Collection<int, QueryScheduleRun> $runs
 * @property-read Collection<int, QueryAlert> $alerts
 */
#[Fillable([
    'user_id',
    'query_id',
    'oracle_tenant_id',
    'name',
    'tenant_key',
    'frequency',
    'time_of_day',
    'day_of_week',
    'timezone',
    'is_active',
    'last_run_at',
    'next_run_at',
    'last_status',
])]
class QuerySchedule extends Model
{
    /** @use HasFactory<QueryScheduleFactory> */
    use HasFactory;

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'frequency' => ScheduleFrequency::class,
            'day_of_week' => 'integer',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `query()` is reserved by Eloquent, so the relation carries an explicit name.
     *
     * @return BelongsTo<Query, $this>
     */
    public function executedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<OracleTenant, $this> */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /** @return HasMany<QueryScheduleRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(QueryScheduleRun::class);
    }

    /** @return HasMany<QueryAlert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(QueryAlert::class);
    }
}
