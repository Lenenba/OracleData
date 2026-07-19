<?php

namespace App\Models;

use Database\Factories\OracleResourceSchemaSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable Oracle `/describe` schema captured after a successful synchronization.
 *
 * @property int $id
 * @property int $oracle_tenant_id
 * @property int|null $previous_snapshot_id
 * @property int|null $triggered_by_user_id
 * @property int|null $semantic_catalog_version_id
 * @property string $resource_key
 * @property string $child
 * @property string $source
 * @property string|null $title
 * @property list<string> $fields
 * @property list<array<string, mixed>> $attributes
 * @property string $schema_hash
 * @property array{added: list<string>, removed: list<string>, changed: list<string>} $diff
 * @property Carbon $synced_at
 * @property-read OracleTenant $oracleTenant
 * @property-read OracleResourceSchemaSnapshot|null $previousSnapshot
 * @property-read User|null $triggeredBy
 * @property-read SemanticCatalogVersion|null $semanticCatalogVersion
 */
#[Fillable([
    'oracle_tenant_id',
    'previous_snapshot_id',
    'triggered_by_user_id',
    'semantic_catalog_version_id',
    'resource_key',
    'child',
    'source',
    'title',
    'fields',
    'attributes',
    'schema_hash',
    'diff',
    'synced_at',
])]
class OracleResourceSchemaSnapshot extends Model
{
    /** @use HasFactory<OracleResourceSchemaSnapshotFactory> */
    use HasFactory;

    public const string CREATED_AT = 'synced_at';

    public const null UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Les snapshots de schéma Oracle sont immuables.');
        });

        static::deleting(function (): never {
            throw new LogicException('Les snapshots de schéma Oracle ne peuvent pas être supprimés.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'attributes' => 'array',
            'diff' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OracleTenant, $this> */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /** @return BelongsTo<OracleResourceSchemaSnapshot, $this> */
    public function previousSnapshot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_snapshot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /** @return BelongsTo<SemanticCatalogVersion, $this> */
    public function semanticCatalogVersion(): BelongsTo
    {
        return $this->belongsTo(SemanticCatalogVersion::class);
    }

    /** @return HasMany<OracleSchemaImpact, $this> */
    public function impacts(): HasMany
    {
        return $this->hasMany(OracleSchemaImpact::class);
    }
}
