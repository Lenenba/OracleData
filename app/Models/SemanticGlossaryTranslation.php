<?php

namespace App\Models;

use Database\Factories\SemanticGlossaryTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'semantic_glossary_term_id',
    'locale',
    'term',
    'definition',
    'synonyms',
    'forbidden_terms',
    'examples',
])]
class SemanticGlossaryTranslation extends Model
{
    /** @use HasFactory<SemanticGlossaryTranslationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'forbidden_terms' => 'array',
            'examples' => 'array',
        ];
    }

    /** @return BelongsTo<SemanticGlossaryTerm, $this> */
    public function semanticGlossaryTerm(): BelongsTo
    {
        return $this->belongsTo(SemanticGlossaryTerm::class);
    }
}
