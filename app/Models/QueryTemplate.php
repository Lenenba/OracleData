<?php

namespace App\Models;

use App\Enums\QueryTemplateGovernanceStatus;
use Database\Factories\QueryTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property QueryTemplateGovernanceStatus $governance_status
 * @property int|null $published_version_id
 * @property int|null $business_owner_user_id
 * @property int|null $technical_owner_user_id
 * @property Carbon|null $review_due_at
 * @property Carbon|null $published_at
 * @property int|null $published_by_user_id
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_user_id
 * @property int $lock_version
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category|null $category
 * @property-read User|null $businessOwner
 * @property-read User|null $technicalOwner
 * @property-read Collection<int, User> $governanceUsers
 * @property-read User|null $publishedBy
 * @property-read User|null $archivedBy
 * @property-read QueryTemplateVersion|null $publishedVersion
 * @property-read Collection<int, QueryTemplateVersion> $versions
 * @property-read Collection<int, QueryTemplateCertification> $certifications
 * @property-read QueryTemplateCertification|null $activeCertification
 * @property-read Collection<int, Query> $queries
 * @property-read Collection<int, QueryExecution> $executions
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
    'governance_status',
    'published_version_id',
    'business_owner_user_id',
    'technical_owner_user_id',
    'review_due_at',
    'published_at',
    'published_by_user_id',
    'archived_at',
    'archived_by_user_id',
    'lock_version',
    'sort_order',
])]
class QueryTemplate extends Model
{
    /** @use HasFactory<QueryTemplateFactory> */
    use HasFactory;

    /**
     * The public model stays immutable. Only the governance workflow may
     * project a reviewed version onto the user-facing record.
     */
    private bool $governanceMutation = false;

    protected static function booted(): void
    {
        static::updating(function (QueryTemplate $template): void {
            if (! $template->governanceMutation) {
                throw new LogicException('Les modèles de requête sont immuables.');
            }
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
            'governance_status' => QueryTemplateGovernanceStatus::class,
            'review_due_at' => 'date',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'lock_version' => 'integer',
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
        return $query
            ->where('is_active', true)
            ->where('governance_status', QueryTemplateGovernanceStatus::PUBLISHED->value)
            ->whereNotNull('published_version_id');
    }

    public function isPublished(): bool
    {
        return $this->is_active
            && $this->governance_status === QueryTemplateGovernanceStatus::PUBLISHED
            && $this->published_version_id !== null;
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

    /** @return HasMany<QueryTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(QueryTemplateVersion::class);
    }

    /** @return HasMany<QueryTemplateCertification, $this> */
    public function certifications(): HasMany
    {
        return $this->hasMany(QueryTemplateCertification::class);
    }

    /** @return HasOne<QueryTemplateCertification, $this> */
    public function activeCertification(): HasOne
    {
        return $this->hasOne(QueryTemplateCertification::class)
            ->where('active_slot', QueryTemplateCertification::ACTIVE_SLOT)
            ->whereNull('revoked_at');
    }

    /** @return BelongsTo<QueryTemplateVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(QueryTemplateVersion::class, 'published_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function businessOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_owner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function technicalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_owner_user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function governanceUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'query_template_role_user')
            ->using(QueryTemplateRoleAssignment::class)
            ->withPivot(['role_id', 'assigned_by_user_id'])
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    /**
     * Resolve the display name in the requested locale, then French, then the
     * historical source stored on the template itself.
     */
    public function nameFor(string $locale): string
    {
        $localized = $this->translationFor($locale);
        $french = $locale === 'fr' ? $localized : $this->translationFor('fr');

        if ($localized !== null) {
            return $localized->name;
        }

        if ($french !== null) {
            return $french->name;
        }

        return $this->name;
    }

    /**
     * Resolve the description field independently so a partial translation
     * can safely fall back without discarding the translated name.
     */
    public function descriptionFor(string $locale): ?string
    {
        $localized = $this->translationFor($locale);
        $french = $locale === 'fr' ? $localized : $this->translationFor('fr');

        if ($localized !== null && $localized->description !== null) {
            return $localized->description;
        }

        if ($french !== null && $french->description !== null) {
            return $french->description;
        }

        return $this->description;
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

        $localizedLabels = $localized === null ? [] : ($localized->parameter_labels ?? []);
        $frenchLabels = $french === null ? [] : ($french->parameter_labels ?? []);
        $localizedDescriptions = $localized === null ? [] : ($localized->parameter_descriptions ?? []);
        $frenchDescriptions = $french === null ? [] : ($french->parameter_descriptions ?? []);
        $localizedOptions = $localized === null ? [] : ($localized->parameter_options ?? []);
        $frenchOptions = $french === null ? [] : ($french->parameter_options ?? []);

        return array_map(
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
        );
    }

    /** @return HasMany<Query, $this> */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /** @return HasMany<QueryExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(QueryExecution::class);
    }

    /**
     * Apply workflow-controlled metadata or a published projection while
     * retaining the existing protection against arbitrary model updates.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyGovernanceProjection(array $attributes): void
    {
        $this->governanceMutation = true;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->governanceMutation = false;
        }
    }

    /** @return array<string, mixed> */
    public function definitionSnapshot(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'resource_key' => $this->resource_key,
            'resource_path' => $this->resource_path,
            'parameters' => $this->parameters ?? [],
            'parameter_definitions' => $this->parameter_definitions ?? [],
            'sort_order' => $this->sort_order,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function translationSnapshot(): array
    {
        return $this->translations
            ->sortBy('locale')
            ->mapWithKeys(fn (QueryTemplateTranslation $translation): array => [
                $translation->locale => [
                    'name' => $translation->name,
                    'description' => $translation->description,
                    'parameter_labels' => $translation->parameter_labels ?? [],
                    'parameter_descriptions' => $translation->parameter_descriptions ?? [],
                    'parameter_options' => $translation->parameter_options ?? [],
                ],
            ])
            ->all();
    }

    private function translationFor(string $locale): ?QueryTemplateTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }
}
