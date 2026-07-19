<?php

namespace App\Models;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Enums\SemanticSqlMappingStatus;
use Database\Factories\SemanticResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Governed semantic representation of one allow-listed Oracle resource.
 *
 * @property int $id
 * @property string $resource_key
 * @property string $source_name
 * @property string $domain
 * @property string $api_path
 * @property int|null $business_owner_user_id
 * @property int|null $technical_owner_user_id
 * @property SemanticClassification $classification
 * @property SemanticDataCategory $data_category
 * @property string|null $sql_table
 * @property string|null $sql_alias
 * @property SemanticSqlMappingStatus $sql_mapping_status
 * @property string|null $mapping_notes
 * @property bool $is_active
 * @property int $lock_version
 * @property-read User|null $businessOwner
 * @property-read User|null $technicalOwner
 * @property-read Collection<int, SemanticResourceTranslation> $translations
 * @property-read Collection<int, SemanticField> $fields
 * @property-read Collection<int, SemanticRelation> $outgoingRelations
 * @property-read Collection<int, SemanticRelation> $incomingRelations
 */
#[Fillable([
    'resource_key',
    'source_name',
    'domain',
    'api_path',
    'business_owner_user_id',
    'technical_owner_user_id',
    'classification',
    'data_category',
    'sql_table',
    'sql_alias',
    'sql_mapping_status',
    'mapping_notes',
    'is_active',
    'lock_version',
])]
class SemanticResource extends Model
{
    /** @use HasFactory<SemanticResourceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'classification' => SemanticClassification::class,
            'data_category' => SemanticDataCategory::class,
            'sql_mapping_status' => SemanticSqlMappingStatus::class,
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function businessOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function technicalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_owner_user_id');
    }

    /** @return HasMany<SemanticResourceTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(SemanticResourceTranslation::class);
    }

    /** @return HasMany<SemanticField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(SemanticField::class);
    }

    /** @return HasMany<SemanticRelation, $this> */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(SemanticRelation::class, 'source_resource_id');
    }

    /** @return HasMany<SemanticRelation, $this> */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(SemanticRelation::class, 'target_resource_id');
    }
}
