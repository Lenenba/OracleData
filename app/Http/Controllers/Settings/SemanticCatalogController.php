<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SemanticClassification;
use App\Enums\SemanticSqlMappingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSemanticResourceRequest;
use App\Models\SemanticField;
use App\Models\SemanticFieldTranslation;
use App\Models\SemanticGlossaryTerm;
use App\Models\SemanticRelation;
use App\Models\SemanticRelationTranslation;
use App\Models\SemanticResource;
use App\Models\SemanticResourceTranslation;
use App\Models\User;
use App\Services\OracleResourceCatalog;
use App\Services\SemanticCatalogGovernanceService;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SemanticCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SemanticResource::class);
        $search = trim($request->string('search')->toString());
        $domain = trim($request->string('domain')->toString());
        $classification = SemanticClassification::tryFrom(
            $request->string('classification')->toString(),
        );
        $resources = SemanticResource::query()
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('resource_key', 'like', "%{$search}%")
                    ->orWhere('source_name', 'like', "%{$search}%")
                    ->orWhereHas('translations', fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%")),
            ))
            ->when($domain !== '', fn (Builder $query) => $query->where('domain', $domain))
            ->when($classification !== null, fn (Builder $query) => $query
                ->where('classification', $classification))
            ->with(['businessOwner:id,name,email', 'technicalOwner:id,name,email', 'translations'])
            ->withCount([
                'fields',
                'outgoingRelations as relations_count',
                'fields as mapped_fields_count' => fn (Builder $query) => $query
                    ->where('sql_mapping_status', SemanticSqlMappingStatus::Exact),
            ])
            ->orderBy('domain')
            ->orderBy('resource_key')
            ->get();
        $glossary = SemanticGlossaryTerm::query()
            ->with('translations')
            ->orderBy('term_key')
            ->get();

        return Inertia::render('settings/semantic-catalog/index', [
            'resources' => $resources->map(fn (SemanticResource $resource): array => $this->resource($resource)),
            'glossary' => $glossary->map(fn (SemanticGlossaryTerm $term): array => $this->glossary($term)),
            'summary' => [
                'resources' => SemanticResource::query()->count(),
                'fields' => SemanticField::query()->count(),
                'relations' => SemanticRelation::query()->count(),
                'glossary_terms' => SemanticGlossaryTerm::query()->count(),
                'mapped_fields' => SemanticField::query()
                    ->where('sql_mapping_status', SemanticSqlMappingStatus::Exact)
                    ->count(),
            ],
            'filters' => [
                'search' => $search,
                'domain' => $domain === '' ? null : $domain,
                'classification' => $classification?->value,
            ],
            'domains' => SemanticResource::query()->distinct()->orderBy('domain')->pluck('domain'),
            'ownerCandidates' => $this->owners(),
            'capabilities' => $this->capabilities($request->user()),
        ]);
    }

    public function show(
        Request $request,
        SemanticResource $semanticResource,
        OracleResourceCatalog $oracleCatalog,
    ): Response {
        Gate::authorize('view', $semanticResource);
        $semanticResource->load([
            'businessOwner:id,name,email',
            'technicalOwner:id,name,email',
            'translations',
            'fields.translations',
            'outgoingRelations.translations',
        ])->loadCount([
            'fields',
            'outgoingRelations as relations_count',
            'fields as mapped_fields_count' => fn (Builder $query) => $query
                ->where('sql_mapping_status', SemanticSqlMappingStatus::Exact),
        ]);
        $locale = app()->getLocale();
        $oracleResource = $oracleCatalog->find($semanticResource->resource_key);
        $allowedJoins = $oracleResource === null ? [] : $oracleResource['join_keys'];
        $allowedChildren = $oracleResource === null ? [] : $oracleResource['child_resources'];

        return Inertia::render('settings/semantic-catalog/show', [
            'resource' => [
                ...$this->resource($semanticResource),
                'fields' => $semanticResource->fields
                    ->sortBy(fn (SemanticField $field): string => $field->child_key.'.'.$field->source_name)
                    ->values()
                    ->map(fn (SemanticField $field): array => [
                        'id' => $field->id,
                        'child_key' => $field->child_key,
                        'source_name' => $field->source_name,
                        'data_type' => $field->data_type,
                        'classification' => $this->value($field->classification),
                        'data_category' => $this->value($field->data_category),
                        'sql_expression' => $field->sql_expression,
                        'sql_mapping_status' => $this->value($field->sql_mapping_status),
                        'is_nullable' => (bool) $field->is_nullable,
                        'is_updatable' => (bool) $field->is_updatable,
                        'is_active' => $field->is_active,
                        'last_seen_at' => $this->isoDate($field->last_seen_at),
                        'lock_version' => $field->lock_version,
                        'translations' => $field->translations->map(fn (SemanticFieldTranslation $translation): array => [
                            'locale' => $translation->locale,
                            'name' => $translation->name,
                            'description' => $translation->description,
                            'synonyms' => $translation->synonyms ?? [],
                            'examples' => $translation->examples ?? [],
                        ])->values()->all(),
                    ])
                    ->all(),
                'relations' => $semanticResource->outgoingRelations
                    ->sortBy('relation_key')
                    ->values()
                    ->map(fn (SemanticRelation $relation): array => [
                        'id' => $relation->id,
                        'relation_key' => $relation->relation_key,
                        'kind' => $this->value($relation->kind),
                        'target_key' => $relation->target_key,
                        'source_field' => $relation->source_field ?? '',
                        'target_field' => $relation->target_field ?? '',
                        'cardinality' => $this->value($relation->cardinality),
                        'sql_table' => $relation->sql_table,
                        'sql_alias' => $relation->sql_alias,
                        'sql_join' => $relation->sql_join,
                        'status' => $this->value($relation->status),
                        'is_active' => $relation->is_active,
                        'lock_version' => $relation->lock_version,
                        'translations' => $relation->translations->map(fn (SemanticRelationTranslation $translation): array => [
                            'locale' => $translation->locale,
                            'name' => $translation->name,
                            'description' => $translation->description,
                        ])->values()->all(),
                    ])
                    ->all(),
            ],
            'ownerCandidates' => $this->owners(),
            'targetResources' => SemanticResource::query()
                ->where('is_active', true)
                ->whereIn('resource_key', array_keys($allowedJoins))
                ->with(['translations', 'fields:id,semantic_resource_id,source_name'])
                ->orderBy('resource_key')
                ->get()
                ->map(fn (SemanticResource $resource): array => [
                    'id' => $resource->id,
                    'resource_key' => $resource->resource_key,
                    'name' => $this->resourceName($resource, $locale),
                    'fields' => $resource->fields->pluck('source_name')->unique()->values(),
                    'local_key' => (string) ($allowedJoins[$resource->resource_key]['local_key'] ?? ''),
                    'remote_key' => (string) ($allowedJoins[$resource->resource_key]['remote_key'] ?? ''),
                ]),
            'childResources' => collect($allowedChildren)->map(fn (string $child): array => [
                'key' => $child,
                'name' => str($child)->headline()->toString(),
            ])->values(),
            'capabilities' => $this->capabilities($request->user(), $semanticResource),
        ]);
    }

    public function update(
        UpdateSemanticResourceRequest $request,
        SemanticResource $semanticResource,
        SemanticCatalogGovernanceService $governance,
    ): RedirectResponse {
        $governance->updateResource($semanticResource, $request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ressource sémantique mise à jour.')]);

        return back();
    }

    /** @return array<string, mixed> */
    private function resource(SemanticResource $resource): array
    {
        return [
            'id' => $resource->id,
            'resource_key' => $resource->resource_key,
            'source_name' => $resource->source_name,
            'domain' => $resource->domain,
            'api_path' => $resource->api_path,
            'business_owner' => $this->owner($resource->businessOwner),
            'technical_owner' => $this->owner($resource->technicalOwner),
            'classification' => $this->value($resource->classification),
            'data_category' => $this->value($resource->data_category),
            'sql_table' => $resource->sql_table,
            'sql_alias' => $resource->sql_alias,
            'sql_mapping_status' => $this->value($resource->sql_mapping_status),
            'mapping_notes' => $resource->mapping_notes,
            'is_active' => $resource->is_active,
            'lock_version' => $resource->lock_version,
            'translations' => $resource->translations->map(fn ($translation): array => [
                'locale' => $translation->locale,
                'name' => $translation->name,
                'description' => $translation->description,
                'synonyms' => $translation->synonyms ?? [],
                'examples' => $translation->examples ?? [],
            ])->values(),
            'fields_count' => (int) $resource->getAttribute('fields_count'),
            'relations_count' => (int) $resource->getAttribute('relations_count'),
            'mapped_fields_count' => (int) $resource->getAttribute('mapped_fields_count'),
        ];
    }

    /** @return array<string, mixed> */
    private function glossary(SemanticGlossaryTerm $term): array
    {
        return [
            'id' => $term->id,
            'term_key' => $term->term_key,
            'domain' => $term->domain ?? '',
            'classification' => $this->value($term->classification),
            'data_category' => $this->value($term->data_category),
            'is_active' => $term->is_active,
            'lock_version' => $term->lock_version,
            'translations' => $term->translations->map(fn ($translation): array => [
                'locale' => $translation->locale,
                'term' => $translation->term,
                'definition' => $translation->definition,
                'synonyms' => $translation->synonyms ?? [],
                'forbidden_terms' => $translation->forbidden_terms ?? [],
                'examples' => $translation->examples ?? [],
            ])->values(),
        ];
    }

    /** @return list<array{id: int, name: string, email: string}> */
    private function owners(): array
    {
        $owners = User::query()->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all();

        return [...$owners];
    }

    /** @return array{id: int, name: string, email: string}|null */
    private function owner(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    private function resourceName(SemanticResource $resource, string $locale): string
    {
        $translation = $this->translationForLocale($resource->translations, $locale)
            ?? $this->translationForLocale($resource->translations, 'fr');

        return $translation === null ? $resource->source_name : $translation->name;
    }

    /**
     * @param  iterable<SemanticResourceTranslation>  $translations
     */
    private function translationForLocale(iterable $translations, string $locale): ?SemanticResourceTranslation
    {
        foreach ($translations as $translation) {
            if ($translation->locale === $locale) {
                return $translation;
            }
        }

        return null;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, bool> */
    private function capabilities(User $user, ?SemanticResource $resource = null): array
    {
        $subject = $resource ?? new SemanticResource;

        return [
            'view' => $user->can('viewAny', SemanticResource::class),
            'update_resource' => $user->can('update', $subject),
            'update_fields' => $user->can('manageFields', $subject),
            'manage_relations' => $user->can('manageRelations', $subject),
            'manage_glossary' => $user->can('create', SemanticGlossaryTerm::class),
        ];
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
