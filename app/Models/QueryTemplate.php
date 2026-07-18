<?php

namespace App\Models;

use Database\Factories\QueryTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Immutable, system-owned query definition that users may execute or copy.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int|null $category_id
 * @property string $resource_key
 * @property string $resource_path
 * @property array<string, mixed> $parameters
 * @property list<array<string, mixed>> $parameter_definitions
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 * @property-read Collection<int, Query> $queries
 * @property-read Collection<int, QueryTemplateTranslation> $translations
 */
#[Fillable([
    'slug',
    'name',
    'description',
    'category_id',
    'resource_key',
    'resource_path',
    'parameters',
    'parameter_definitions',
    'is_active',
    'sort_order',
])]
class QueryTemplate extends Model
{
    /** @use HasFactory<QueryTemplateFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Les modèles de requête sont immuables.');
        });

        static::deleting(function (): never {
            throw new LogicException('Les modèles de requête ne peuvent pas être supprimés.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'parameter_definitions' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Only active templates are exposed to the user-facing library.
     *
     * @param  Builder<QueryTemplate>  $query
     * @return Builder<QueryTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<QueryTemplateTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(QueryTemplateTranslation::class);
    }

    /**
     * Resolve the display name in the requested locale, then French, then the
     * historical source stored on the template itself.
     */
    public function nameFor(string $locale): string
    {
        $localized = $this->translationFor($locale);
        $french = $locale === 'fr' ? $localized : $this->translationFor('fr');

        return $localized?->name ?? $french?->name ?? $this->name;
    }

    /**
     * Resolve the description field independently so a partial translation
     * can safely fall back without discarding the translated name.
     */
    public function descriptionFor(string $locale): ?string
    {
        $localized = $this->translationFor($locale);
        $french = $locale === 'fr' ? $localized : $this->translationFor('fr');

        return $localized?->description ?? $french?->description ?? $this->description;
    }

    /**
     * Localize presentation-only parameter content. Structural and security
     * fields such as keys, types, bounds and bindings always come from the
     * immutable base definition.
     *
     * @return list<array<string, mixed>>
     */
    public function parameterDefinitionsFor(string $locale): array
    {
        $localized = $this->translationFor($locale);
        $french = $locale === 'fr' ? $localized : $this->translationFor('fr');

        $localizedLabels = $localized?->parameter_labels ?? [];
        $frenchLabels = $french?->parameter_labels ?? [];
        $localizedDescriptions = $localized?->parameter_descriptions ?? [];
        $frenchDescriptions = $french?->parameter_descriptions ?? [];
        $localizedOptions = $localized?->parameter_options ?? [];
        $frenchOptions = $french?->parameter_options ?? [];

        return array_values(array_map(
            function (array $definition) use (
                $localizedLabels,
                $frenchLabels,
                $localizedDescriptions,
                $frenchDescriptions,
                $localizedOptions,
                $frenchOptions,
            ): array {
                $key = (string) ($definition['key'] ?? '');

                if (array_key_exists($key, $localizedLabels)) {
                    $definition['label'] = $localizedLabels[$key];
                } elseif (array_key_exists($key, $frenchLabels)) {
                    $definition['label'] = $frenchLabels[$key];
                }

                if (array_key_exists($key, $localizedDescriptions)) {
                    $definition['description'] = $localizedDescriptions[$key];
                } elseif (array_key_exists($key, $frenchDescriptions)) {
                    $definition['description'] = $frenchDescriptions[$key];
                }

                if (! is_array($definition['options'] ?? null)) {
                    return $definition;
                }

                $localizedOptionLabels = is_array($localizedOptions[$key] ?? null)
                    ? $localizedOptions[$key]
                    : [];
                $frenchOptionLabels = is_array($frenchOptions[$key] ?? null)
                    ? $frenchOptions[$key]
                    : [];

                $definition['options'] = array_values(array_map(
                    function (array $option) use ($localizedOptionLabels, $frenchOptionLabels): array {
                        $value = (string) ($option['value'] ?? '');

                        if (array_key_exists($value, $localizedOptionLabels)) {
                            $option['label'] = $localizedOptionLabels[$value];
                        } elseif (array_key_exists($value, $frenchOptionLabels)) {
                            $option['label'] = $frenchOptionLabels[$value];
                        }

                        return $option;
                    },
                    $definition['options'],
                ));

                return $definition;
            },
            $this->parameter_definitions ?? [],
        ));
    }

    /** @return HasMany<Query, $this> */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    private function translationFor(string $locale): ?QueryTemplateTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }
}
