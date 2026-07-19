<?php

namespace App\Models;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Enums\SemanticSqlMappingStatus;
use Database\Factories\SemanticFieldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'semantic_resource_id',
    'child_key',
    'source_name',
    'data_type',
    'classification',
    'data_category',
    'sql_expression',
    'sql_mapping_status',
    'is_nullable',
    'is_updatable',
    'is_active',
    'last_seen_at',
    'lock_version',
])]
class SemanticField extends Model
{
    /** @use HasFactory<SemanticFieldFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'classification' => SemanticClassification::class,
            'data_category' => SemanticDataCategory::class,
            'sql_mapping_status' => SemanticSqlMappingStatus::class,
            'is_nullable' => 'boolean',
            'is_updatable' => 'boolean',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<SemanticResource, $this> */
    public function semanticResource(): BelongsTo
    {
        return $this->belongsTo(SemanticResource::class);
    }

    /** @return HasMany<SemanticFieldTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(SemanticFieldTranslation::class);
    }
}
