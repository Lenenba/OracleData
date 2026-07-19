<?php

namespace App\Models;

use App\Enums\ReferenceScenarioType;
use Database\Factories\QueryTemplateReferenceDatasetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable, value-free fingerprint captured from a governed template run.
 *
 * @property int $id
 * @property int $query_template_id
 * @property int $query_template_version_id
 * @property int|null $oracle_tenant_id
 * @property int|null $auth_connection_id
 * @property int|null $captured_by_user_id
 * @property string $name
 * @property ReferenceScenarioType $scenario
 * @property string $parameter_hash
 * @property list<string> $parameter_keys
 * @property array<string, mixed> $comparison_config
 * @property array<string, mixed> $fingerprint
 * @property int $row_count
 * @property string $dataset_hash
 * @property Carbon $captured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryTemplate $queryTemplate
 * @property-read QueryTemplateVersion $queryTemplateVersion
 * @property-read OracleTenant|null $oracleTenant
 * @property-read AuthConnection|null $authConnection
 * @property-read User|null $capturedBy
 * @property-read Collection<int, QueryTemplateValidationRun> $validationRuns
 */
#[Fillable([
    'query_template_id',
    'query_template_version_id',
    'oracle_tenant_id',
    'auth_connection_id',
    'captured_by_user_id',
    'name',
    'scenario',
    'parameter_hash',
    'parameter_keys',
    'comparison_config',
    'fingerprint',
    'row_count',
    'dataset_hash',
    'captured_at',
])]
class QueryTemplateReferenceDataset extends Model
{
    /** @use HasFactory<QueryTemplateReferenceDatasetFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Les jeux de référence sont immuables.');
        });

        static::deleting(function (): never {
            throw new LogicException('Les jeux de référence ne peuvent pas être supprimés.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scenario' => ReferenceScenarioType::class,
            'parameter_keys' => 'array',
            'comparison_config' => 'array',
            'fingerprint' => 'array',
            'row_count' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QueryTemplate, $this> */
    public function queryTemplate(): BelongsTo
    {
        return $this->belongsTo(QueryTemplate::class);
    }

    /** @return BelongsTo<QueryTemplateVersion, $this> */
    public function queryTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(QueryTemplateVersion::class);
    }

    /** @return BelongsTo<OracleTenant, $this> */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /** @return BelongsTo<AuthConnection, $this> */
    public function authConnection(): BelongsTo
    {
        return $this->belongsTo(AuthConnection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    /** @return HasMany<QueryTemplateValidationRun, $this> */
    public function validationRuns(): HasMany
    {
        return $this->hasMany(QueryTemplateValidationRun::class);
    }
}
