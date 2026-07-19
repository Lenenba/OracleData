<?php

namespace App\Models;

use Database\Factories\SemanticFieldTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'semantic_field_id',
    'locale',
    'name',
    'description',
    'synonyms',
    'examples',
])]
class SemanticFieldTranslation extends Model
{
    /** @use HasFactory<SemanticFieldTranslationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'examples' => 'array',
        ];
    }

    /** @return BelongsTo<SemanticField, $this> */
    public function semanticField(): BelongsTo
    {
        return $this->belongsTo(SemanticField::class);
    }
}
