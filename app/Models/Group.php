<?php

namespace App\Models;

use App\Enums\GroupRole;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $owner
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, QueryGroupShare> $queryShares
 */
#[Fillable(['owner_id', 'name', 'description'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<QueryGroupShare, $this> */
    public function queryShares(): HasMany
    {
        return $this->hasMany(QueryGroupShare::class);
    }

    /**
     * Groups visible to one of their current members.
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $groups) use ($user): void {
            $groups
                ->where('owner_id', $user->id)
                ->orWhereHas('members', fn (Builder $members): Builder => $members
                    ->whereKey($user->id));
        });
    }

    public function roleFor(User $user): ?GroupRole
    {
        if ($this->owner_id === $user->id) {
            return GroupRole::OWNER;
        }

        if ($this->relationLoaded('members')) {
            $member = $this->members->firstWhere('id', $user->id);
            $role = $member?->getRelation('pivot')->getAttribute('role');
        } else {
            $role = $this->members()
                ->whereKey($user->id)
                ->value('group_user.role');
        }

        return is_string($role) ? GroupRole::tryFrom($role) : null;
    }

    public function hasMember(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function canManageMembers(User $user): bool
    {
        return $this->roleFor($user)?->canManageMembers() ?? false;
    }
}
