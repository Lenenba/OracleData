<?php

namespace App\Models;

use App\Enums\DataQualityRunPurpose;
use App\Enums\DataQualityRunStatus;
use Database\Factories\QueryTemplateValidationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable outcome of evaluating one exact template version and rule set.
 *
 * @property int $id
 * @property int $query_template_id
 * @property int $query_template_version_id
 * @property int|null $query_execution_id
 * @property int|null $query_template_reference_dataset_id
 * @property int|null $oracle_tenant_id
 * @property int|null $auth_connection_id
 * @property int|null $run_by_user_id
 * @property DataQualityRunPurpose $purpose
 * @property DataQualityRunStatus $status
 * @property string $version_content_hash
 * @property string $rules_hash
 * @property string $parameter_hash
 * @property list<array<string, mixed>>|null $assertion_results
 * @property string|null $score
 * @property int $duration_ms
 * @property int $row_count
 * @property string|null $error_code
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryTemplate $queryTemplate
 * @property-read QueryTemplateVersion $queryTemplateVersion
 * @property-read QueryExecution|null $queryExecution
 * @property-read QueryTemplateReferenceDataset|null $referenceDataset
 * @property-read OracleTenant|null $oracleTenant
 * @property-read AuthConnection|null $authConnection
 * @property-read User|null $runBy
 */
#[Fillable([
    'query_template_id',
    'query_template_version_id',
    'query_execution_id',
    'query_template_reference_dataset_id',
    'oracle_tenant_id',
    'auth_connection_id',
    'run_by_user_id',
    'purpose',
    'status',
    'version_content_hash',
    'rules_hash',
    'parameter_hash',
    'assertion_results',
    'score',
    'duration_ms',
    'row_count',
    'error_code',
    'started_at',
    'finished_at',
])]
class QueryTemplateValidationRun extends Model
{
    /** @use HasFactory<QueryTemplateValidationRunFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Les validations de qualité sont immuables.');
        });

        static::deleting(function (): never {
            throw new LogicException('Les validations de qualité ne peuvent pas être supprimées.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => DataQualityRunPurpose::class,
            'status' => DataQualityRunStatus::class,
            'assertion_results' => 'array',
            'score' => 'decimal:2',
            'duration_ms' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    /** @return BelongsTo<QueryExecution, $this> */
    public function queryExecution(): BelongsTo
    {
        return $this->belongsTo(QueryExecution::class);
    }

    /** @return BelongsTo<QueryTemplateReferenceDataset, $this> */
    public function referenceDataset(): BelongsTo
    {
        return $this->belongsTo(
            QueryTemplateReferenceDataset::class,
            'query_template_reference_dataset_id',
        );
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
    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_user_id');
    }
}
