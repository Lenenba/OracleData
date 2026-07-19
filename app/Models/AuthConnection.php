<?php

namespace App\Models;

use Database\Factories\AuthConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $oracle_tenant_id
 * @property string $name
 * @property string $auth_type
 * @property string $identifier
 * @property string $secret
 * @property array<string, mixed>|null $configuration
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_tested_at
 * @property Carbon|null $last_test_succeeded_at
 * @property-read User $user
 * @property-read OracleTenant $oracleTenant
 * @property-read Collection<int, QueryTemplateReferenceDataset> $queryTemplateReferenceDatasets
 * @property-read Collection<int, QueryTemplateValidationRun> $queryTemplateValidationRuns
 */
#[Fillable([
    'user_id',
    'oracle_tenant_id',
    'name',
    'auth_type',
    'identifier',
    'secret',
    'configuration',
    'is_default',
    'is_active',
    'verified_at',
    'last_tested_at',
    'last_test_succeeded_at',
])]
#[Hidden(['secret', 'configuration'])]
class AuthConnection extends Model
{
    /** @use HasFactory<AuthConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'configuration' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_test_succeeded_at' => 'datetime',
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
     * @return BelongsTo<OracleTenant, $this>
     */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /** @return HasMany<QueryTemplateReferenceDataset, $this> */
    public function queryTemplateReferenceDatasets(): HasMany
    {
        return $this->hasMany(QueryTemplateReferenceDataset::class);
    }

    /** @return HasMany<QueryTemplateValidationRun, $this> */
    public function queryTemplateValidationRuns(): HasMany
    {
        return $this->hasMany(QueryTemplateValidationRun::class);
    }
}
