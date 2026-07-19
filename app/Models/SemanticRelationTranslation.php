<?php

namespace App\Models;

use Database\Factories\SemanticRelationTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'semantic_relation_id',
    'locale',
    'name',
    'description',
])]
class SemanticRelationTranslation extends Model
{
    /** @use HasFactory<SemanticRelationTranslationFactory> */
    use HasFactory;

    /** @return BelongsTo<SemanticRelation, $this> */
    public function semanticRelation(): BelongsTo
    {
        return $this->belongsTo(SemanticRelation::class);
    }
}
