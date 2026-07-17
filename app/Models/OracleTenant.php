<?php

namespace App\Models;

use Database\Factories\OracleTenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $label
 * @property string $base_url
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, AuthConnection> $authConnections
 */
#[Fillable(['user_id', 'key', 'label', 'base_url', 'is_default', 'is_active'])]
class OracleTenant extends Model
{
    /** @use HasFactory<OracleTenantFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AuthConnection, $this>
     */
    public function authConnections(): HasMany
    {
        return $this->hasMany(AuthConnection::class);
    }

    /**
     * @return HasMany<Query, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }
}
