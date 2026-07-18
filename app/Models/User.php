<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
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
 */
#[Fillable(['name', 'email', 'password', 'locale', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
     * The queries owned by the user.
     *
     * @return HasMany<Query, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
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
