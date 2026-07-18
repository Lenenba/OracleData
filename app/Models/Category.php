<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie administrée de la bibliothèque, traduite via ses translations.
 *
 * @property int $id
 * @property string $slug
 * @property string|null $color
 * @property-read Collection<int, CategoryTranslation> $translations
 * @property-read Collection<int, Query> $queries
 */
#[Fillable(['slug', 'color'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @return HasMany<CategoryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    /**
     * @return HasMany<Query, $this>
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /**
     * Libellé dans la locale demandée, avec repli vers le français puis le slug.
     */
    public function nameFor(string $locale): string
    {
        $translations = $this->translations->keyBy('locale');
        $localized = $translations->get($locale);

        if ($localized !== null) {
            return $localized->name;
        }

        $french = $translations->get('fr');

        return $french !== null ? $french->name : $this->slug;
    }
}
