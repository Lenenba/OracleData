<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tag libre réutilisable, unique par slug normalisé.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property-read Collection<int, Query> $queries
 * @property-read Collection<int, TagTranslation> $translations
 */
#[Fillable(['name', 'slug'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<Query, $this>
     */
    public function queries(): BelongsToMany
    {
        return $this->belongsToMany(Query::class);
    }

    /**
     * Official localized labels. Free-form tags can legitimately have none.
     *
     * @return HasMany<TagTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(TagTranslation::class);
    }

    /**
     * Label in the requested locale, with French then source-name fallback.
     */
    public function nameFor(string $locale): string
    {
        $translations = $this->translations->keyBy('locale');
        $localized = $translations->get($locale);

        if ($localized !== null) {
            return $localized->name;
        }

        $french = $translations->get('fr');

        return $french !== null ? $french->name : $this->name;
    }

    /**
     * Retrouve ou crée le tag correspondant à un nom saisi librement.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = trim($name);
        $slug = Str::slug($name);

        if ($slug === '') {
            throw new InvalidArgumentException('A tag name must produce a non-empty slug.');
        }

        return self::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
