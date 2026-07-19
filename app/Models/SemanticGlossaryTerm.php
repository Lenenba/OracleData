<?php

namespace App\Models;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use Database\Factories\SemanticGlossaryTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'term_key',
    'domain',
    'classification',
    'data_category',
    'is_active',
    'lock_version',
])]
class SemanticGlossaryTerm extends Model
{
    /** @use HasFactory<SemanticGlossaryTermFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'classification' => SemanticClassification::class,
            'data_category' => SemanticDataCategory::class,
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /** @return HasMany<SemanticGlossaryTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(SemanticGlossaryTranslation::class);
    }
}
