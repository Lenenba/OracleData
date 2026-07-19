<?php

namespace App\Models;

use App\Enums\QueryChangeRequestStatus;
use Database\Factories\QueryChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * @property int $id
 * @property int $query_id
 * @property int|null $requested_by_user_id
 * @property string $title
 * @property QueryChangeRequestStatus $status
 * @property int|null $status_changed_by_user_id
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $comments_count
 * @property-read Query $subjectQuery
 * @property-read User|null $requestedBy
 * @property-read User|null $statusChangedBy
 * @property-read Collection<int, QueryChangeRequestComment> $comments
 */
#[Fillable([
    'query_id',
    'requested_by_user_id',
    'title',
    'status',
    'status_changed_by_user_id',
    'status_changed_at',
])]
class QueryChangeRequest extends Model
{
    /** @use HasFactory<QueryChangeRequestFactory> */
    use HasFactory;

    private bool $transitioning = false;

    protected static function booted(): void
    {
        static::saving(function (self $changeRequest): void {
            if (
                $changeRequest->exists
                && ! $changeRequest->transitioning
                && $changeRequest->isDirty([
                    'status',
                    'status_changed_by_user_id',
                    'status_changed_at',
                ])
            ) {
                throw new LogicException('Query change-request status must be changed through transitionTo().');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QueryChangeRequestStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Query, $this> */
    public function subjectQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    /** @return HasMany<QueryChangeRequestComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(QueryChangeRequestComment::class);
    }

    /**
     * Apply the lifecycle once. A replay of the current state is intentionally
     * a no-op so it cannot duplicate audit events or notifications.
     */
    public function transitionTo(QueryChangeRequestStatus $target, User $actor): bool
    {
        if ($this->status === $target) {
            return false;
        }

        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidArgumentException(
                "Invalid query-change-request transition [{$this->status->value} -> {$target->value}].",
            );
        }

        $this->transitioning = true;

        try {
            $this->forceFill([
                'status' => $target,
                'status_changed_by_user_id' => $actor->id,
                'status_changed_at' => now(),
            ])->save();
        } finally {
            $this->transitioning = false;
        }

        return true;
    }
}
