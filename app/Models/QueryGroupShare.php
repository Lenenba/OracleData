<?php

namespace App\Models;

use App\Enums\QuerySharePermission;
use Carbon\CarbonInterface;
use Database\Factories\QueryGroupShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A revocable grant from a query to every current member of a group.
 *
 * @property int $id
 * @property int $query_id
 * @property int $group_id
 * @property string $group_name
 * @property int|null $shared_by_user_id
 * @property QuerySharePermission $permission
 * @property string $status
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 * @property-read Query $sharedQuery
 * @property-read Group|null $group
 * @property-read User|null $sharedByUser
 */
#[Fillable([
    'query_id',
    'group_id',
    'group_name',
    'shared_by_user_id',
    'permission',
    'status',
    'expires_at',
    'accepted_at',
    'revoked_at',
])]
class QueryGroupShare extends Model
{
    /** @use HasFactory<QueryGroupShareFactory> */
    use HasFactory;

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_REVOKED = 'revoked';

    /** @var array<string, mixed> */
    protected $attributes = [
        'permission' => QuerySharePermission::VIEW->value,
        'status' => self::STATUS_ACCEPTED,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $share): void {
            if (! in_array($share->status, [self::STATUS_ACCEPTED, self::STATUS_REVOKED], true)) {
                throw new InvalidArgumentException("Unknown query-group-share status [{$share->status}].");
            }

            if ($share->permission === QuerySharePermission::MANAGE) {
                throw new InvalidArgumentException('Group shares cannot delegate query-sharing management.');
            }

            if ($share->status === self::STATUS_ACCEPTED) {
                $share->accepted_at ??= now();
                $share->revoked_at = null;

                return;
            }

            $share->revoked_at ??= now();
        });

        // Preserve the label as it was when this grant cycle was created.
        // Renaming a group must not rewrite historical share snapshots.
        static::creating(function (self $share): void {
            $share->group_name = Group::query()->findOrFail($share->group_id)->name;
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'permission' => QuerySharePermission::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Query, $this> */
    public function sharedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sharedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }

    /**
     * @param  Builder<QueryGroupShare>  $query
     * @return Builder<QueryGroupShare>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', self::STATUS_ACCEPTED)
            ->whereNotNull('accepted_at')
            ->where('accepted_at', '<=', $at)
            ->whereNull('revoked_at')
            ->whereHas('group')
            ->where(function (Builder $expiry) use ($at): void {
                $expiry->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $at);
            });
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

    public function allows(QuerySharePermission $required, ?CarbonInterface $at = null): bool
    {
        return $this->isActive($at)
            && $this->permission !== QuerySharePermission::MANAGE
            && $this->permission->implies($required);
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
