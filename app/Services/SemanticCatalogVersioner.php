<?php

namespace App\Services;

use App\Enums\SemanticCatalogVersionStatus;
use App\Models\SemanticCatalogVersion;
use App\Models\SemanticField;
use App\Models\SemanticFieldTranslation;
use App\Models\SemanticGlossaryTerm;
use App\Models\SemanticGlossaryTranslation;
use App\Models\SemanticRelation;
use App\Models\SemanticRelationTranslation;
use App\Models\SemanticResource;
use App\Models\SemanticResourceTranslation;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\DB;

class SemanticCatalogVersioner
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array{version: SemanticCatalogVersion, created: bool}
     */
    public function capture(
        ?User $actor = null,
        ?string $changeSummary = null,
    ): array {
        return DB::transaction(function () use ($actor, $changeSummary): array {
            $catalog = $this->snapshot();
            $contentHash = SemanticCatalogVersion::contentHash($catalog);
            $published = SemanticCatalogVersion::query()
                ->where('published_slot', SemanticCatalogVersion::PUBLISHED_SLOT)
                ->lockForUpdate()
                ->first();

            if ($published !== null && hash_equals($published->content_hash, $contentHash)) {
                return ['version' => $published, 'created' => false];
            }

            $latest = SemanticCatalogVersion::query()
                ->latest('version_number')
                ->lockForUpdate()
                ->first();
            $now = now();

            if ($published !== null) {
                $published->forceFill([
                    'status' => SemanticCatalogVersionStatus::Superseded,
                    'published_slot' => null,
                    'lock_version' => $published->lock_version + 1,
                ])->save();
            }

            $nextVersionNumber = $latest === null
                ? 1
                : $latest->version_number + 1;
            $version = SemanticCatalogVersion::query()->create([
                'version_number' => $nextVersionNumber,
                'status' => SemanticCatalogVersionStatus::Published,
                'open_slot' => null,
                'published_slot' => SemanticCatalogVersion::PUBLISHED_SLOT,
                'catalog' => $catalog,
                'content_hash' => $contentHash,
                'change_summary' => $changeSummary,
                'created_by_user_id' => $actor?->id,
                'submitted_by_user_id' => $actor?->id,
                'published_by_user_id' => $actor?->id,
                'submitted_at' => $now,
                'published_at' => $now,
                'lock_version' => 1,
            ]);

            $this->audit->record($actor, 'semantic.catalog_version_created', $version, [
                'catalog_version_id' => $version->id,
                'version_number' => $version->version_number,
                'content_hash' => $contentHash,
                ...$this->counts($catalog),
            ]);

            return ['version' => $version, 'created' => true];
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $resources = SemanticResource::query()
            ->with([
                'translations' => fn ($query) => $query->orderBy('locale'),
                'fields' => fn ($query) => $query
                    ->orderBy('child_key')
                    ->orderBy('source_name'),
                'fields.translations' => fn ($query) => $query->orderBy('locale'),
                'outgoingRelations' => fn ($query) => $query->orderBy('relation_key'),
                'outgoingRelations.translations' => fn ($query) => $query->orderBy('locale'),
                'outgoingRelations.targetResource:id,resource_key',
            ])
            ->orderBy('resource_key')
            ->get()
            ->map(fn (SemanticResource $resource): array => [
                'resource_key' => $resource->resource_key,
                'source_name' => $resource->source_name,
                'domain' => $resource->domain,
                'api_path' => $resource->api_path,
                'business_owner_user_id' => $resource->business_owner_user_id,
                'technical_owner_user_id' => $resource->technical_owner_user_id,
                'classification' => $this->enumValue($resource->classification),
                'data_category' => $this->enumValue($resource->data_category),
                'sql_table' => $resource->sql_table,
                'sql_alias' => $resource->sql_alias,
                'sql_mapping_status' => $this->enumValue($resource->sql_mapping_status),
                'mapping_notes' => $resource->mapping_notes,
                'is_active' => $resource->is_active,
                'translations' => $resource->translations->map(fn (SemanticResourceTranslation $translation): array => [
                    'locale' => $translation->locale,
                    'name' => $translation->name,
                    'description' => $translation->description,
                    'synonyms' => $translation->synonyms ?? [],
                    'examples' => $translation->examples ?? [],
                ])->all(),
                'fields' => $resource->fields->map(fn (SemanticField $field): array => [
                    'child_key' => $field->child_key,
                    'source_name' => $field->source_name,
                    'data_type' => $field->data_type,
                    'classification' => $this->enumValue($field->classification),
                    'data_category' => $this->enumValue($field->data_category),
                    'sql_expression' => $field->sql_expression,
                    'sql_mapping_status' => $this->enumValue($field->sql_mapping_status),
                    'is_nullable' => $field->is_nullable,
                    'is_updatable' => $field->is_updatable,
                    'is_active' => $field->is_active,
                    'translations' => $field->translations->map(fn (SemanticFieldTranslation $translation): array => [
                        'locale' => $translation->locale,
                        'name' => $translation->name,
                        'description' => $translation->description,
                        'synonyms' => $translation->synonyms ?? [],
                        'examples' => $translation->examples ?? [],
                    ])->all(),
                ])->all(),
                'relations' => $resource->outgoingRelations->map(fn (SemanticRelation $relation): array => [
                    'relation_key' => $relation->relation_key,
                    'kind' => $this->enumValue($relation->kind),
                    'target_key' => $relation->target_key,
                    'target_resource_key' => $relation->targetResource?->resource_key,
                    'source_field' => $relation->source_field,
                    'target_field' => $relation->target_field,
                    'cardinality' => $this->enumValue($relation->cardinality),
                    'sql_table' => $relation->sql_table,
                    'sql_alias' => $relation->sql_alias,
                    'sql_join' => $relation->sql_join,
                    'status' => $this->enumValue($relation->status),
                    'is_active' => $relation->is_active,
                    'translations' => $relation->translations->map(fn (SemanticRelationTranslation $translation): array => [
                        'locale' => $translation->locale,
                        'name' => $translation->name,
                        'description' => $translation->description,
                    ])->all(),
                ])->all(),
            ])
            ->all();
        $glossary = SemanticGlossaryTerm::query()
            ->with(['translations' => fn ($query) => $query->orderBy('locale')])
            ->orderBy('term_key')
            ->get()
            ->map(fn (SemanticGlossaryTerm $term): array => [
                'term_key' => $term->term_key,
                'domain' => $term->domain,
                'classification' => $this->enumValue($term->classification),
                'data_category' => $this->enumValue($term->data_category),
                'is_active' => $term->is_active,
                'translations' => $term->translations->map(fn (SemanticGlossaryTranslation $translation): array => [
                    'locale' => $translation->locale,
                    'term' => $translation->term,
                    'definition' => $translation->definition,
                    'synonyms' => $translation->synonyms ?? [],
                    'forbidden_terms' => $translation->forbidden_terms ?? [],
                    'examples' => $translation->examples ?? [],
                ])->all(),
            ])
            ->all();

        return $this->canonicalize([
            'schema_version' => 1,
            'resources' => $resources,
            'glossary' => $glossary,
        ]);
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);

        if (array_is_list($canonical)) {
            usort($canonical, fn (mixed $left, mixed $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));

            return $canonical;
        }

        ksort($canonical);

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array{resource_count: int, field_count: int, relation_count: int, glossary_count: int}
     */
    private function counts(array $catalog): array
    {
        $resources = $catalog['resources'] ?? [];

        return [
            'resource_count' => count($resources),
            'field_count' => array_sum(array_map(
                fn (array $resource): int => count($resource['fields'] ?? []),
                $resources,
            )),
            'relation_count' => array_sum(array_map(
                fn (array $resource): int => count($resource['relations'] ?? []),
                $resources,
            )),
            'glossary_count' => count($catalog['glossary'] ?? []),
        ];
    }
}
