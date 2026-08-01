<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\QueryTemplateRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $avatar_path
 * @property-read string|null $avatar
 * @property string $password
 * @property bool $is_super_admin
 * @property string $locale
 * @property string $timezone
 * @property Carbon|null $onboarding_completed_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, QueryUserShare> $receivedQueryShares
 * @property-read Collection<int, QueryUserShare> $grantedQueryShares
 * @property-read Collection<int, Group> $groups
 * @property-read Collection<int, Group> $ownedGroups
 * @property-read Collection<int, QueryChangeRequest> $requestedQueryChanges
 * @property-read Collection<int, QueryChangeRequestComment> $queryChangeRequestComments
 * @property-read Collection<int, Role> $queryTemplateRoles
 * @property-read Collection<int, QueryTemplate> $technicallyOwnedQueryTemplates
 * @property-read Collection<int, QueryTemplateReferenceDataset> $capturedReferenceDatasets
 * @property-read Collection<int, QueryTemplateValidationRun> $queryTemplateValidationRuns
 */
#[Appends(['avatar'])]
#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['avatar_path', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** @var list<int>|null */
    private ?array $queryAccessGroupIds = null;

    /** Correlation ID of the HTTP request for which group IDs were cached. */
    private ?string $queryAccessGroupRequestId = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Public URL of the user's profile photo, without exposing its storage path.
     *
     * @return Attribute<covariant string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->avatar_path === null
                ? null
                : Storage::disk('public')->url($this->avatar_path),
        );
    }

    /**
     * The queries owned by the user.
     *
     * @return HasMany<Query, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /** @return HasMany<PersonalApiToken, $this> */
    public function personalApiTokens(): HasMany
    {
        return $this->hasMany(PersonalApiToken::class);
    }

    /**
     * Direct query grants received by this user.
     *
     * @return HasMany<QueryUserShare, $this>
     */
    public function receivedQueryShares(): HasMany
    {
        return $this->hasMany(QueryUserShare::class);
    }

    /**
     * Direct query grants created by this user on behalf of an owner or
     * delegated sharing manager. Historical rows survive account deletion.
     *
     * @return HasMany<QueryUserShare, $this>
     */
    public function grantedQueryShares(): HasMany
    {
        return $this->hasMany(QueryUserShare::class, 'shared_by_user_id');
    }

    /** @return HasMany<QueryChangeRequest, $this> */
    public function requestedQueryChanges(): HasMany
    {
        return $this->hasMany(QueryChangeRequest::class, 'requested_by_user_id');
    }

    /** @return HasMany<QueryChangeRequestComment, $this> */
    public function queryChangeRequestComments(): HasMany
    {
        return $this->hasMany(QueryChangeRequestComment::class);
    }

    /** @return HasMany<Group, $this> */
    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Role, $this, QueryTemplateRoleAssignment, 'pivot'> */
    public function queryTemplateRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'query_template_role_user')
            ->using(QueryTemplateRoleAssignment::class)
            ->withPivot(['query_template_id', 'assigned_by_user_id'])
            ->withTimestamps();
    }

    /** @return HasMany<QueryTemplate, $this> */
    public function technicallyOwnedQueryTemplates(): HasMany
    {
        return $this->hasMany(QueryTemplate::class, 'technical_owner_user_id');
    }

    public function hasQueryTemplateRole(QueryTemplate $template, QueryTemplateRole $role): bool
    {
        return $this->queryTemplateRoles()
            ->wherePivot('query_template_id', $template->id)
            ->where('roles.name', $role->value)
            ->exists();
    }

    /** @return list<string> */
    public function queryTemplateRoleNames(QueryTemplate $template): array
    {
        return array_values($this->queryTemplateRoles()
            ->wherePivot('query_template_id', $template->id)
            ->orderBy('roles.name')
            ->get(['roles.name'])
            ->map(static fn (Role $role): string => $role->name)
            ->all());
    }

    /**
     * Cache current active group IDs for repeated Policy checks in one request.
     *
     * The authenticated User instance can outlive a request in feature tests or
     * long-running workers. Tying the cache to the Request object prevents a
     * removed member from retaining access during the next request.
     *
     * @return list<int>
     */
    public function groupIdsForQueryAccess(): array
    {
        $contextRequestId = Context::get('request_id');
        $requestId = is_string($contextRequestId) && $contextRequestId !== ''
            ? $contextRequestId
            : null;

        if (
            $requestId !== null
            && $this->queryAccessGroupRequestId === $requestId
            && $this->queryAccessGroupIds !== null
        ) {
            return $this->queryAccessGroupIds;
        }

        $groupIds = array_values($this->groups()
            ->pluck('groups.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all());

        if ($requestId !== null) {
            $this->queryAccessGroupIds = $groupIds;
            $this->queryAccessGroupRequestId = $requestId;
        }

        return $groupIds;
    }

    /**
     * Personal favorite and pin states for accessible queries.
     *
     * @return HasMany<QueryUserPreference, $this>
     */
    public function queryPreferences(): HasMany
    {
        return $this->hasMany(QueryUserPreference::class);
    }

    /**
     * Named library filter combinations owned by the user.
     *
     * @return HasMany<SavedQueryView, $this>
     */
    public function savedQueryViews(): HasMany
    {
        return $this->hasMany(SavedQueryView::class);
    }

    /**
     * Oracle environments owned and managed by the user.
     *
     * @return HasMany<OracleTenant, $this>
     */
    public function oracleTenants(): HasMany
    {
        return $this->hasMany(OracleTenant::class);
    }

    /**
     * Authentication connections owned by the user.
     *
     * @return HasMany<AuthConnection, $this>
     */
    public function authConnections(): HasMany
    {
        return $this->hasMany(AuthConnection::class);
    }

    /** @return HasMany<QueryTemplateReferenceDataset, $this> */
    public function capturedReferenceDatasets(): HasMany
    {
        return $this->hasMany(QueryTemplateReferenceDataset::class, 'captured_by_user_id');
    }

    /** @return HasMany<QueryTemplateValidationRun, $this> */
    public function queryTemplateValidationRuns(): HasMany
    {
        return $this->hasMany(QueryTemplateValidationRun::class, 'run_by_user_id');
    }

    /**
     * Historical product milestone; runtime access still requires a usable
     * active connection, including a fresh verification for legacy imports.
     */
    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * Determine whether the user has unrestricted platform administration access.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Get the locale used for localized notifications sent to this user.
     */
    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
