<?php

namespace App\Models;

use App\Enums\OracleSchemaImpactKind;
use App\Enums\OracleSchemaImpactSeverity;
use App\Enums\OracleSchemaImpactStatus;
use Database\Factories\OracleSchemaImpactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $oracle_resource_schema_snapshot_id
 * @property int $oracle_tenant_id
 * @property int|null $semantic_catalog_version_id
 * @property int|null $query_id
 * @property int|null $query_template_version_id
 * @property string $resource_key
 * @property string $child_key
 * @property OracleSchemaImpactKind $kind
 * @property OracleSchemaImpactSeverity $severity
 * @property OracleSchemaImpactStatus $status
 * @property list<string> $affected_fields
 * @property string $fingerprint
 * @property Carbon $detected_at
 * @property int|null $acknowledged_by_user_id
 * @property Carbon|null $acknowledged_at
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_notes
 */
#[Fillable([
    'oracle_resource_schema_snapshot_id',
    'oracle_tenant_id',
    'semantic_catalog_version_id',
    'query_id',
    'query_template_version_id',
    'resource_key',
    'child_key',
    'kind',
    'severity',
    'status',
    'affected_fields',
    'fingerprint',
    'detected_at',
    'acknowledged_by_user_id',
    'acknowledged_at',
    'resolved_by_user_id',
    'resolved_at',
    'resolution_notes',
])]
class OracleSchemaImpact extends Model
{
    /** @use HasFactory<OracleSchemaImpactFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => OracleSchemaImpactKind::class,
            'severity' => OracleSchemaImpactSeverity::class,
            'status' => OracleSchemaImpactStatus::class,
            'affected_fields' => 'array',
            'detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OracleResourceSchemaSnapshot, $this> */
    public function oracleResourceSchemaSnapshot(): BelongsTo
    {
        return $this->belongsTo(OracleResourceSchemaSnapshot::class);
    }

    /** @return BelongsTo<OracleTenant, $this> */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /** @return BelongsTo<SemanticCatalogVersion, $this> */
    public function semanticCatalogVersion(): BelongsTo
    {
        return $this->belongsTo(SemanticCatalogVersion::class);
    }

    /** @return BelongsTo<Query, $this> */
    public function executedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<QueryTemplateVersion, $this> */
    public function queryTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(QueryTemplateVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
