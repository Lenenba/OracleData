<?php

namespace App\Models;

use Database\Factories\SemanticResourceTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'semantic_resource_id',
    'locale',
    'name',
    'description',
    'synonyms',
    'examples',
])]
class SemanticResourceTranslation extends Model
{
    /** @use HasFactory<SemanticResourceTranslationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'examples' => 'array',
        ];
    }

    /** @return BelongsTo<SemanticResource, $this> */
    public function semanticResource(): BelongsTo
    {
        return $this->belongsTo(SemanticResource::class);
    }
}
