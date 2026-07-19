<?php

namespace App\Models;

use App\Enums\QuerySharePermission;
use Carbon\CarbonInterface;
use Database\Factories\QueryUserShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A direct, revocable grant from a query owner or delegated manager.
 *
 * @property int $id
 * @property int $query_id
 * @property int|null $shared_by_user_id
 * @property int $user_id
 * @property QuerySharePermission $permission
 * @property string $status
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $respond_by
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $declined_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Query $sharedQuery
 * @property-read User|null $sharedByUser
 * @property-read User $user
 */
#[Fillable([
    'query_id',
    'shared_by_user_id',
    'user_id',
    'permission',
    'status',
    'expires_at',
    'respond_by',
    'accepted_at',
    'declined_at',
    'cancelled_at',
    'revoked_at',
])]
class QueryUserShare extends Model
{
    /** @use HasFactory<QueryUserShareFactory> */
    use HasFactory;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_DECLINED = 'declined';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string STATUS_REVOKED = 'revoked';

    /** @var array<string, mixed> */
    protected $attributes = [
        'permission' => QuerySharePermission::VIEW->value,
        'status' => self::STATUS_ACCEPTED,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $share): void {
            if (! in_array($share->status, [
                self::STATUS_PENDING,
                self::STATUS_ACCEPTED,
                self::STATUS_DECLINED,
                self::STATUS_CANCELLED,
                self::STATUS_REVOKED,
            ], true)) {
                throw new InvalidArgumentException("Unknown query-share status [{$share->status}].");
            }

            if ($share->exists && $share->isDirty('status')) {
                $previous = (string) $share->getRawOriginal('status');
                $allowedTransitions = [
                    self::STATUS_PENDING => [
                        self::STATUS_ACCEPTED,
                        self::STATUS_DECLINED,
                        self::STATUS_CANCELLED,
                    ],
                    self::STATUS_ACCEPTED => [self::STATUS_REVOKED],
                    self::STATUS_DECLINED => [],
                    self::STATUS_CANCELLED => [],
                    self::STATUS_REVOKED => [],
                ];

                if (! in_array($share->status, $allowedTransitions[$previous] ?? [], true)) {
                    throw new InvalidArgumentException(
                        "Invalid query-share transition [{$previous} -> {$share->status}].",
                    );
                }
            }

            if ($share->status === self::STATUS_PENDING) {
                if ($share->respond_by === null) {
                    throw new InvalidArgumentException('Pending query-share invitations require a response deadline.');
                }

                $share->accepted_at = null;
                $share->declined_at = null;
                $share->cancelled_at = null;
                $share->revoked_at = null;

                return;
            }

            if ($share->status === self::STATUS_ACCEPTED) {
                $share->accepted_at ??= now();
                $share->declined_at = null;
                $share->cancelled_at = null;
                $share->revoked_at = null;

                return;
            }

            if ($share->status === self::STATUS_DECLINED) {
                $share->accepted_at = null;
                $share->declined_at ??= now();
                $share->cancelled_at = null;
                $share->revoked_at = null;

                return;
            }

            if ($share->status === self::STATUS_CANCELLED) {
                $share->cancelled_at ??= now();
                $share->revoked_at = null;

                return;
            }

            $share->revoked_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission' => QuerySharePermission::class,
            'expires_at' => 'datetime',
            'respond_by' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Query, $this> */
    public function sharedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sharedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<QueryUserShare>  $query
     * @return Builder<QueryUserShare>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<QueryUserShare>  $query
     * @return Builder<QueryUserShare>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', self::STATUS_ACCEPTED)
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $at)
            ->whereNull('revoked_at')
            ->where(function (Builder $expiry) use ($at): void {
                $expiry->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $at);
            });
    }

    /**
     * Invitations that can still be answered by their intended recipient.
     *
     * @param  Builder<QueryUserShare>  $query
     * @return Builder<QueryUserShare>
     */
    public function scopePending(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', self::STATUS_PENDING)
            ->whereNotNull('respond_by')
            ->where('respond_by', '>', $at)
            ->where(function (Builder $expiry) use ($at): void {
                $expiry->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $at);
            });
    }

    /**
     * Active grants and invitations awaiting a response both reserve the
     * query/recipient pair against duplicate lifecycle creation.
     *
     * @param  Builder<QueryUserShare>  $query
     * @return Builder<QueryUserShare>
     */
    public function scopeCurrent(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where(function (Builder $current) use ($at): void {
            $current
                ->where(fn (Builder $active) => $active->active($at))
                ->orWhere(fn (Builder $pending) => $pending->pending($at));
        });
    }

    /**
     * @param  Builder<QueryUserShare>  $query
     * @return Builder<QueryUserShare>
     */
    public function scopePermitting(Builder $query, QuerySharePermission $required): Builder
    {
        return $query->whereIn('permission', QuerySharePermission::valuesGranting($required));
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_ACCEPTED
            && $this->accepted_at !== null
            && $this->accepted_at->lte($at)
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->gt($at));
    }

    public function isPending(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_PENDING
            && $this->respond_by !== null
            && $this->respond_by->gt($at)
            && ($this->expires_at === null || $this->expires_at->gt($at));
    }

    public function isCurrent(?CarbonInterface $at = null): bool
    {
        return $this->isActive($at) || $this->isPending($at);
    }

    public function allows(QuerySharePermission $required, ?CarbonInterface $at = null): bool
    {
        return $this->isActive($at) && $this->permission->implies($required);
    }

    public function accept(?CarbonInterface $at = null): bool
    {
        $this->forceFill([
            'status' => self::STATUS_ACCEPTED,
            'accepted_at' => $at ?? now(),
            'revoked_at' => null,
        ]);

        return $this->save();
    }

    public function decline(?CarbonInterface $at = null): bool
    {
        $this->forceFill([
            'status' => self::STATUS_DECLINED,
            'declined_at' => $at ?? now(),
        ]);

        return $this->save();
    }

    public function cancel(?CarbonInterface $at = null): bool
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => $at ?? now(),
        ]);

        return $this->save();
    }

    public function revoke(?CarbonInterface $at = null): bool
    {
        $this->forceFill([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => $at ?? now(),
        ]);

        return $this->save();
    }
}
