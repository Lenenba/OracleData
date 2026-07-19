<?php

namespace App\Models;

use App\Enums\SemanticCardinality;
use App\Enums\SemanticRelationKind;
use App\Enums\SemanticRelationStatus;
use Database\Factories\SemanticRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_resource_id',
    'target_resource_id',
    'relation_key',
    'kind',
    'target_key',
    'source_field',
    'target_field',
    'cardinality',
    'sql_table',
    'sql_alias',
    'sql_join',
    'status',
    'is_active',
    'lock_version',
])]
class SemanticRelation extends Model
{
    /** @use HasFactory<SemanticRelationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SemanticRelationKind::class,
            'cardinality' => SemanticCardinality::class,
            'status' => SemanticRelationStatus::class,
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<SemanticResource, $this> */
    public function sourceResource(): BelongsTo
    {
        return $this->belongsTo(SemanticResource::class, 'source_resource_id');
    }

    /** @return BelongsTo<SemanticResource, $this> */
    public function targetResource(): BelongsTo
    {
        return $this->belongsTo(SemanticResource::class, 'target_resource_id');
    }

    /** @return HasMany<SemanticRelationTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(SemanticRelationTranslation::class);
    }
}
