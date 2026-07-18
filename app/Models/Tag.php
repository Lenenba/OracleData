<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Tag libre réutilisable, unique par slug normalisé.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property-read Collection<int, Query> $queries
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
     * Retrouve ou crée le tag correspondant à un nom saisi librement.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = trim($name);
        $slug = Str::slug($name);

        return self::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
