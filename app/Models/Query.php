<?php

namespace App\Models;

use App\Enums\OracleExecutionPolicy;
use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use Database\Factories\QueryFactory;
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
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property string|null $resource_path
 * @property string|null $tenant_key
 * @property int|null $oracle_tenant_id
 * @property string $mode
 * @property OracleExecutionPolicy $execution_policy
 * @property array<string, mixed>|null $parameters
 * @property list<array<string, mixed>>|null $parameter_definitions
 * @property array<string, mixed>|null $query_graph
 * @property QueryAccessLevel $access_level
 * @property int|null $category_id
 * @property int|null $query_template_id
 * @property int|null $query_template_version_id
 * @property int $execution_count
 * @property int $successful_execution_count
 * @property Carbon|null $last_executed_at
 * @property Carbon|null $last_successful_execution_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read OracleTenant|null $oracleTenant
 * @property-read Collection<int, QueryExecution> $executions
 * @property-read Category|null $category
 * @property-read QueryTemplate|null $queryTemplate
 * @property-read QueryTemplateVersion|null $queryTemplateVersion
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, QueryUserPreference> $preferences
 * @property-read Collection<int, QueryUserShare> $userShares
 * @property-read Collection<int, QueryGroupShare> $groupShares
 * @property-read Collection<int, QueryChangeRequest> $changeRequests
 * @property-read Collection<int, QuerySemanticResource> $semanticResources
 * @property-read Collection<int, OracleSchemaImpact> $schemaImpacts
 */
#[Fillable(['name', 'description', 'resource_path', 'query_graph', 'tenant_key', 'oracle_tenant_id', 'mode', 'execution_policy', 'parameters', 'parameter_definitions', 'access_level', 'category_id', 'query_template_id', 'query_template_version_id'])]
class Query extends Model
{
    /** @use HasFactory<QueryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'parameter_definitions' => 'array',
            'query_graph' => 'array',
            'execution_policy' => OracleExecutionPolicy::class,
            'access_level' => QueryAccessLevel::class,
            'execution_count' => 'integer',
            'successful_execution_count' => 'integer',
            'last_executed_at' => 'datetime',
            'last_successful_execution_at' => 'datetime',
        ];
    }

    /**
     * The user that owns the query.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owner's preferred Oracle environment for this query.
     *
     * Shared readers must always resolve their own execution environment.
     *
     * @return BelongsTo<OracleTenant, $this>
     */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /**
     * Execution history, kept even after the query is deleted (nullable FK).
     *
     * @return HasMany<QueryExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(QueryExecution::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The predefined template from which this independent query was created.
     *
     * @return BelongsTo<QueryTemplate, $this>
     */
    public function queryTemplate(): BelongsTo
    {
        return $this->belongsTo(QueryTemplate::class);
    }

    /**
     * Exact official version from which this independent query was cloned.
     * The value is provenance metadata and is never editable by user input.
     *
     * @return BelongsTo<QueryTemplateVersion, $this>
     */
    public function queryTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(QueryTemplateVersion::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Per-user favorites and pins for this accessible query.
     *
     * @return HasMany<QueryUserPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(QueryUserPreference::class);
    }

    /**
     * Direct grants for individual recipients.
     *
     * @return HasMany<QueryUserShare, $this>
     */
    public function userShares(): HasMany
    {
        return $this->hasMany(QueryUserShare::class);
    }

    /**
     * Grants inherited by every current member of a group.
     *
     * @return HasMany<QueryGroupShare, $this>
     */
    public function groupShares(): HasMany
    {
        return $this->hasMany(QueryGroupShare::class);
    }

    /** @return HasMany<QueryChangeRequest, $this> */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(QueryChangeRequest::class);
    }

    /** @return HasMany<QuerySemanticResource, $this> */
    public function semanticResources(): HasMany
    {
        return $this->hasMany(QuerySemanticResource::class);
    }

    /** @return HasMany<OracleSchemaImpact, $this> */
    public function schemaImpacts(): HasMany
    {
        return $this->hasMany(OracleSchemaImpact::class);
    }

    /**
     * Queries the user may access, including their own queries.
     *
     * @param  Builder<Query>  $query
     * @return Builder<Query>
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        $ownerColumn = $query->getModel()->qualifyColumn('user_id');
        $accessLevelColumn = $query->getModel()->qualifyColumn('access_level');

        return $query->where(function (Builder $accessible) use ($user, $ownerColumn, $accessLevelColumn): void {
            $accessible
                ->where($ownerColumn, $user->getKey())
                ->orWhere(function (Builder $shared) use ($user, $ownerColumn, $accessLevelColumn): void {
                    $shared
                        ->where($ownerColumn, '!=', $user->getKey())
                        ->where(function (Builder $grant) use ($user, $accessLevelColumn): void {
                            $grant
                                ->where($accessLevelColumn, QueryAccessLevel::ORGANIZATION->value)
                                ->orWhere(function (Builder $restricted) use ($user, $accessLevelColumn): void {
                                    $restricted
                                        ->where($accessLevelColumn, QueryAccessLevel::RESTRICTED->value)
                                        ->where(function (Builder $recipient) use ($user): void {
                                            $recipient
                                                ->whereHas('userShares', fn (Builder $shares): Builder => $shares
                                                    ->where('user_id', $user->getKey())
                                                    ->where('status', QueryUserShare::STATUS_ACCEPTED)
                                                    ->whereNotNull('accepted_at')
                                                    ->where('accepted_at', '<=', now())
                                                    ->whereNull('revoked_at')
                                                    ->where(fn (Builder $expiry): Builder => $expiry
                                                        ->whereNull('expires_at')
                                                        ->orWhere('expires_at', '>', now())))
                                                ->orWhereHas('groupShares', function (Builder $shares) use ($user): void {
                                                    /** @var Builder<QueryGroupShare> $shares */
                                                    $shares
                                                        ->active()
                                                        ->whereIn('group_id', $user->groups()->select('groups.id'));
                                                });
                                        });
                                });
                        });
                });
        });
    }

    /**
     * Queries owned by somebody else and shared organization-wide or through
     * a currently active direct grant.
     *
     * @param  Builder<Query>  $query
     * @return Builder<Query>
     */
    public function scopeSharedWith(Builder $query, User $user): Builder
    {
        $ownerColumn = $query->getModel()->qualifyColumn('user_id');
        $accessLevelColumn = $query->getModel()->qualifyColumn('access_level');

        return $query
            ->where($ownerColumn, '!=', $user->getKey())
            ->where(function (Builder $shared) use ($user, $accessLevelColumn): void {
                $shared
                    ->where($accessLevelColumn, QueryAccessLevel::ORGANIZATION->value)
                    ->orWhere(function (Builder $restricted) use ($user, $accessLevelColumn): void {
                        $restricted
                            ->where($accessLevelColumn, QueryAccessLevel::RESTRICTED->value)
                            ->where(function (Builder $recipient) use ($user): void {
                                $recipient
                                    ->whereHas('userShares', fn (Builder $shares): Builder => $shares
                                        ->where('user_id', $user->getKey())
                                        ->where('status', QueryUserShare::STATUS_ACCEPTED)
                                        ->whereNotNull('accepted_at')
                                        ->where('accepted_at', '<=', now())
                                        ->whereNull('revoked_at')
                                        ->where(fn (Builder $expiry): Builder => $expiry
                                            ->whereNull('expires_at')
                                            ->orWhere('expires_at', '>', now())))
                                    ->orWhereHas('groupShares', function (Builder $shares) use ($user): void {
                                        /** @var Builder<QueryGroupShare> $shares */
                                        $shares
                                            ->active()
                                            ->whereIn('group_id', $user->groups()->select('groups.id'));
                                    });
                            });
                    });
            });
    }

    /**
     * Resolve the strongest active direct grant without re-querying when the
     * userShares relation has already been eager loaded.
     */
    public function activeShareFor(User $user): ?QueryUserShare
    {
        if ($this->relationLoaded('userShares')) {
            return $this->userShares
                ->filter(fn (QueryUserShare $share): bool => $share->user_id === $user->id && $share->isActive())
                ->sortByDesc(fn (QueryUserShare $share): int => $share->permission->rank())
                ->first();
        }

        return $this->userShares()
            ->forUser($user)
            ->active()
            ->get()
            ->sortByDesc(fn (QueryUserShare $share): int => $share->permission->rank())
            ->first();
    }

    /**
     * Resolve the strongest active grant inherited through current groups.
     */
    public function activeGroupShareFor(User $user): ?QueryGroupShare
    {
        $groupIds = $user->groupIdsForQueryAccess();

        if ($groupIds === []) {
            return null;
        }

        if ($this->relationLoaded('groupShares')) {
            return $this->groupShares
                ->filter(fn (QueryGroupShare $share): bool => in_array($share->group_id, $groupIds, true)
                    && $share->isActive())
                ->sortByDesc(fn (QueryGroupShare $share): int => $share->permission->rank())
                ->first();
        }

        return $this->groupShares()
            ->active()
            ->whereIn('group_id', $groupIds)
            ->get()
            ->sortByDesc(fn (QueryGroupShare $share): int => $share->permission->rank())
            ->first();
    }

    /**
     * Organization access keeps the historical shared-query capability:
     * authenticated colleagues may view, execute and clone, but not manage.
     */
    public function permissionFor(User $user): ?QuerySharePermission
    {
        if ($this->user_id === $user->id) {
            return QuerySharePermission::MANAGE;
        }

        if ($this->access_level === QueryAccessLevel::PRIVATE) {
            return null;
        }

        $permission = $this->access_level === QueryAccessLevel::ORGANIZATION
            ? QuerySharePermission::CLONE
            : null;
        $directPermission = $this->activeShareFor($user)?->permission;

        if ($directPermission === QuerySharePermission::MANAGE) {
            return $directPermission;
        }

        if ($permission === QuerySharePermission::CLONE || $directPermission === QuerySharePermission::CLONE) {
            return QuerySharePermission::CLONE;
        }

        $candidatePermissions = [$directPermission, $this->activeGroupShareFor($user)?->permission];

        foreach ($candidatePermissions as $candidate) {
            if ($candidate !== null && ($permission === null || $candidate->rank() > $permission->rank())) {
                $permission = $candidate;
            }
        }

        return $permission;
    }

    public function allows(User $user, QuerySharePermission $required): bool
    {
        return $this->permissionFor($user)?->implies($required) ?? false;
    }
}
