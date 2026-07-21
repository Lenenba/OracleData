<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lot 12B/12C — Token d'API personnel géré manuellement (sans Sanctum).
 *
 * Le secret brut n'est jamais stocké ; seul son hash SHA-256 est persisté.
 * Les scopes contrôlent les opérations autorisées par ce token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property list<string> $scopes
 * @property bool $is_active
 * @property int $requests_today
 * @property int $daily_limit
 * @property string|null $requests_today_date
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'name',
    'token_hash',
    'scopes',
    'is_active',
    'requests_today',
    'daily_limit',
    'requests_today_date',
    'last_used_at',
    'expires_at',
])]
class PersonalApiToken extends Model
{
    public const string SCOPE_READ_QUERIES = 'read:queries';

    public const string SCOPE_RUN_QUERIES  = 'run:queries';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scopes'               => 'array',
            'is_active'            => 'boolean',
            'requests_today'       => 'integer',
            'daily_limit'          => 'integer',
            'last_used_at'         => 'datetime',
            'expires_at'           => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Hash a plain-text token secret for storage/lookup.
     */
    public static function hashSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Generate a secure random token secret (plain-text, show once).
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars
    }

    /**
     * Lot 12C — Check and increment the daily rate-limit counter.
     * Returns false if the quota is already exhausted for today.
     */
    public function consumeQuota(): bool
    {
        $today = now()->toDateString();

        if ($this->requests_today_date !== $today) {
            $this->forceFill([
                'requests_today'      => 0,
                'requests_today_date' => $today,
            ])->save();
        }

        if ($this->requests_today >= $this->daily_limit) {
            return false;
        }

        $this->increment('requests_today');
        $this->forceFill(['last_used_at' => now()])->save();

        return true;
    }
}
