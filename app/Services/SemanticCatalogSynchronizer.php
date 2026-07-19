<?php

namespace App\Services;

use App\Enums\SemanticCardinality;
use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Enums\SemanticRelationKind;
use App\Enums\SemanticRelationStatus;
use App\Enums\SemanticSqlMappingStatus;
use App\Models\Query;
use App\Models\QueryTemplateVersion;
use App\Models\SemanticCatalogVersion;
use App\Models\SemanticGlossaryTerm;
use App\Models\SemanticRelation;
use App\Models\SemanticResource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/** @phpstan-import-type OracleResource from OracleResourceCatalog */
class SemanticCatalogSynchronizer
{
    /** @var list<string> */
    private const array LOCALES = ['fr', 'en', 'es'];

    public function __construct(
        private readonly OracleResourceCatalog $sourceCatalog,
        private readonly SemanticCatalogVersioner $versioner,
        private readonly SemanticLineageService $lineage,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Imports source-controlled defaults without updating any existing row.
     * Existing rows are considered governed and remain authoritative.
     *
     * @return array{
     *     version: SemanticCatalogVersion,
     *     version_created: bool,
     *     lineage: array{queries: int, template_versions: int},
     *     created: array{resources: int, resource_translations: int, fields: int, field_translations: int, relations: int, relation_translations: int, glossary_terms: int, glossary_translations: int}
     * }
     */
    public function synchronize(?User $actor = null): array
    {
        return DB::transaction(function () use ($actor): array {
            $created = $this->emptyCounts();
            $sourceResources = $this->sourceCatalog->all();

            foreach ($sourceResources as $sourceResource) {
                $this->importResource($sourceResource, $created);
            }

            $resources = SemanticResource::query()
                ->whereIn('resource_key', array_column($sourceResources, 'key'))
                ->get()
                ->keyBy('resource_key');

            foreach ($sourceResources as $sourceResource) {
                $source = $resources->get($sourceResource['key']);

                if (! $source instanceof SemanticResource) {
                    throw new RuntimeException("La ressource sémantique [{$sourceResource['key']}] n’a pas été importée.");
                }

                $this->importRelations($source, $sourceResource, $resources, $created);
            }

            $this->importGlossary($created);
            $versionResult = $this->versioner->capture(
                $actor,
                'Synchronisation canonique depuis le catalogue Oracle autorisé.',
            );
            $lineage = $this->backfillLineage($versionResult['created']);

            $this->audit->record($actor, 'semantic.catalog_synchronized', $versionResult['version'], [
                'catalog_version_id' => $versionResult['version']->id,
                'content_hash' => $versionResult['version']->content_hash,
                'version_created' => $versionResult['created'],
                'resource_count' => count($sourceResources),
                'lineage_query_count' => $lineage['queries'],
                'lineage_template_version_count' => $lineage['template_versions'],
                ...array_combine(
                    array_map(fn (string $key): string => "created_{$key}_count", array_keys($created)),
                    array_values($created),
                ),
            ]);

            return [
                'version' => $versionResult['version'],
                'version_created' => $versionResult['created'],
                'lineage' => $lineage,
                'created' => $created,
            ];
        });
    }

    /**
     * @param  OracleResource  $source
     * @param  array<string, int>  $created
     */
    private function importResource(array $source, array &$created): void
    {
        $sql = $this->arrayValue($source['sql'] ?? null);
        $sqlTable = $this->stringValue($sql['table'] ?? null);
        $resource = SemanticResource::query()->firstOrCreate(
            ['resource_key' => $source['key']],
            [
                'source_name' => Str::afterLast($source['path'], '/'),
                'domain' => $source['domain'],
                'api_path' => $source['path'],
                'business_owner_user_id' => null,
                'technical_owner_user_id' => null,
                'classification' => SemanticClassification::Unclassified,
                'data_category' => SemanticDataCategory::General,
                'sql_table' => $sqlTable,
                'sql_alias' => $this->stringValue($sql['alias'] ?? null),
                'sql_mapping_status' => $this->mappingStatus($sqlTable),
                'mapping_notes' => $this->stringValue($sql['note'] ?? null),
                'is_active' => true,
                'lock_version' => 1,
            ],
        );
        $this->incrementIfCreated($resource, $created, 'resources');

        foreach (self::LOCALES as $locale) {
            $translation = $resource->translations()->firstOrCreate(
                ['locale' => $locale],
                [
                    'name' => $this->localizedResourceName($source, $locale),
                    'description' => $locale === 'fr' ? $source['description'] : null,
                    'synonyms' => $this->stringList($source['keywords']),
                    'examples' => [],
                ],
            );
            $this->incrementIfCreated($translation, $created, 'resource_translations');
        }

        $this->importFields(
            $resource,
            '',
            array_merge(
                $source['fields'],
                array_keys($this->arrayValue($sql['columns'] ?? null)),
            ),
            $this->arrayValue($sql['columns'] ?? null),
            $created,
        );

        $childDefinitions = $this->arrayValue($sql['child_tables'] ?? null);
        $childKeys = array_values(array_unique([
            ...$source['child_resources'],
            ...array_keys($source['child_fields']),
            ...array_keys($childDefinitions),
        ]));

        foreach ($childKeys as $childKey) {
            $childDefinition = $this->arrayValue($childDefinitions[$childKey] ?? null);
            $childColumns = $this->arrayValue($childDefinition['columns'] ?? null);

            $this->importFields(
                $resource,
                $childKey,
                array_merge($source['child_fields'][$childKey] ?? [], array_keys($childColumns)),
                $childColumns,
                $created,
            );
        }
    }

    /**
     * @param  list<string>  $fieldNames
     * @param  array<string, mixed>  $sqlColumns
     * @param  array<string, int>  $created
     */
    private function importFields(
        SemanticResource $resource,
        string $childKey,
        array $fieldNames,
        array $sqlColumns,
        array &$created,
    ): void {
        foreach ($this->stringList($fieldNames) as $fieldName) {
            $sqlExpression = $this->stringValue($sqlColumns[$fieldName] ?? null);
            $field = $resource->fields()->firstOrCreate(
                ['child_key' => $childKey, 'source_name' => $fieldName],
                [
                    'data_type' => null,
                    'classification' => SemanticClassification::Unclassified,
                    'data_category' => SemanticDataCategory::General,
                    'sql_expression' => $sqlExpression,
                    'sql_mapping_status' => $this->mappingStatus($sqlExpression),
                    'is_nullable' => null,
                    'is_updatable' => null,
                    'is_active' => true,
                    'last_seen_at' => null,
                    'lock_version' => 1,
                ],
            );
            $this->incrementIfCreated($field, $created, 'fields');

            foreach (self::LOCALES as $locale) {
                $translation = $field->translations()->firstOrCreate(
                    ['locale' => $locale],
                    [
                        'name' => $this->humanize($fieldName),
                        'description' => null,
                        'synonyms' => [],
                        'examples' => [],
                    ],
                );
                $this->incrementIfCreated($translation, $created, 'field_translations');
            }
        }
    }

    /**
     * @param  OracleResource  $sourceDefinition
     * @param  Collection<string, SemanticResource>  $resources
     * @param  array<string, int>  $created
     */
    private function importRelations(
        SemanticResource $source,
        array $sourceDefinition,
        $resources,
        array &$created,
    ): void {
        $sql = $this->arrayValue($sourceDefinition['sql'] ?? null);
        $childDefinitions = $this->arrayValue($sql['child_tables'] ?? null);
        $childKeys = array_values(array_unique([
            ...$sourceDefinition['child_resources'],
            ...array_keys($sourceDefinition['child_fields']),
            ...array_keys($childDefinitions),
        ]));

        foreach ($childKeys as $childKey) {
            $sqlRelation = $this->arrayValue($childDefinitions[$childKey] ?? null);
            $this->importRelation(
                source: $source,
                target: null,
                relationKey: $this->relationKey($sourceDefinition['key'], 'expand', $childKey),
                kind: SemanticRelationKind::Expand,
                targetKey: $childKey,
                sourceField: null,
                targetField: null,
                cardinality: SemanticCardinality::OneToMany,
                sqlRelation: $sqlRelation,
                frenchName: $this->humanize($childKey),
                created: $created,
            );
        }

        $sqlJoins = $this->arrayValue($sql['joins'] ?? null);

        foreach ($sourceDefinition['join_keys'] as $targetKey => $joinDefinition) {
            $target = $resources->get($targetKey);

            $this->importRelation(
                source: $source,
                target: $target instanceof SemanticResource ? $target : null,
                relationKey: $this->relationKey($sourceDefinition['key'], 'join', $targetKey),
                kind: SemanticRelationKind::Join,
                targetKey: $targetKey,
                sourceField: $joinDefinition['local_key'],
                targetField: $joinDefinition['remote_key'],
                cardinality: SemanticCardinality::OneToMany,
                sqlRelation: $this->arrayValue($sqlJoins[$targetKey] ?? null),
                frenchName: $joinDefinition['label'],
                created: $created,
            );
        }
    }

    /** @param array<string, mixed> $sqlRelation */
    private function importRelation(
        SemanticResource $source,
        ?SemanticResource $target,
        string $relationKey,
        SemanticRelationKind $kind,
        string $targetKey,
        ?string $sourceField,
        ?string $targetField,
        SemanticCardinality $cardinality,
        array $sqlRelation,
        string $frenchName,
        array &$created,
    ): void {
        $relation = SemanticRelation::query()->firstOrCreate(
            ['relation_key' => $relationKey],
            [
                'source_resource_id' => $source->id,
                'target_resource_id' => $target?->id,
                'kind' => $kind,
                'target_key' => $targetKey,
                'source_field' => $sourceField,
                'target_field' => $targetField,
                'cardinality' => $cardinality,
                'sql_table' => $this->stringValue($sqlRelation['table'] ?? null),
                'sql_alias' => $this->stringValue($sqlRelation['alias'] ?? null),
                'sql_join' => $this->stringValue($sqlRelation['join'] ?? null),
                'status' => SemanticRelationStatus::Published,
                'is_active' => true,
                'lock_version' => 1,
            ],
        );
        $this->incrementIfCreated($relation, $created, 'relations');

        foreach (self::LOCALES as $locale) {
            $translation = $relation->translations()->firstOrCreate(
                ['locale' => $locale],
                [
                    'name' => $locale === 'fr' ? $frenchName : $this->humanize($targetKey),
                    'description' => null,
                ],
            );
            $this->incrementIfCreated($translation, $created, 'relation_translations');
        }
    }

    /** @param array<string, int> $created */
    private function importGlossary(array &$created): void
    {
        $path = resource_path('i18n/glossary.json');

        if (! File::exists($path)) {
            throw new RuntimeException('Le glossaire source FR/EN/ES est introuvable.');
        }

        $entries = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($entries)) {
            throw new RuntimeException('Le glossaire source FR/EN/ES est invalide.');
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['term'] ?? null)) {
                continue;
            }

            $sourceTerm = trim($entry['term']);

            if ($sourceTerm === '') {
                continue;
            }

            $term = SemanticGlossaryTerm::query()->firstOrCreate(
                ['term_key' => $this->termKey($sourceTerm)],
                [
                    'domain' => $this->stringValue($entry['domain'] ?? null),
                    'classification' => SemanticClassification::Unclassified,
                    'data_category' => SemanticDataCategory::General,
                    'is_active' => true,
                    'lock_version' => 1,
                ],
            );
            $this->incrementIfCreated($term, $created, 'glossary_terms');

            foreach (self::LOCALES as $locale) {
                $localizedTerm = $this->stringValue($entry[$locale] ?? null) ?? $sourceTerm;
                $translation = $term->translations()->firstOrCreate(
                    ['locale' => $locale],
                    [
                        'term' => $localizedTerm,
                        'definition' => $this->glossaryDefinition($entry, $locale),
                        'synonyms' => $this->localizedList($entry['synonyms'] ?? [], $locale),
                        'forbidden_terms' => $this->localizedList($entry['forbidden_terms'] ?? [], $locale),
                        'examples' => $this->localizedList($entry['examples'] ?? [], $locale),
                    ],
                );
                $this->incrementIfCreated($translation, $created, 'glossary_translations');
            }
        }
    }

    /** @param OracleResource $source */
    private function localizedResourceName(array $source, string $locale): string
    {
        return $locale === 'fr'
            ? $source['label']
            : $this->humanize(Str::afterLast($source['path'], '/'));
    }

    private function humanize(string $value): string
    {
        return Str::headline(Str::snake($value));
    }

    private function mappingStatus(?string $expression): SemanticSqlMappingStatus
    {
        if ($expression === null) {
            return SemanticSqlMappingStatus::Unmapped;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9_$#]*(?:\.[A-Za-z][A-Za-z0-9_$#]*)?$/', $expression) === 1
            ? SemanticSqlMappingStatus::Exact
            : SemanticSqlMappingStatus::Derived;
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $value = $this->stringValue($value);

            if ($value !== null) {
                $normalized[$value] = $value;
            }
        }

        natcasesort($normalized);

        return array_values($normalized);
    }

    /** @return list<string> */
    private function localizedList(mixed $value, string $locale): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            $value = $value[$locale] ?? [];
        }

        return is_array($value) ? $this->stringList($value) : [];
    }

    /** @param array<string, mixed> $entry */
    private function glossaryDefinition(array $entry, string $locale): string
    {
        $definition = $entry['definition'] ?? $entry['note'] ?? null;

        if (is_array($definition)) {
            $definition = $definition[$locale] ?? $definition['fr'] ?? null;
        }

        return $this->stringValue($definition) ?? match ($locale) {
            'en' => 'Controlled business term.',
            'es' => 'Término empresarial controlado.',
            default => 'Terme métier contrôlé.',
        };
    }

    private function relationKey(string $source, string $kind, string $target): string
    {
        $key = "{$source}.{$kind}.{$target}";

        if (mb_strlen($key) <= 150) {
            return $key;
        }

        return mb_substr($key, 0, 133).'.'.substr(hash('sha256', $key), 0, 16);
    }

    private function termKey(string $sourceTerm): string
    {
        $key = Str::slug($sourceTerm);

        if ($key === '') {
            $key = substr(hash('sha256', $sourceTerm), 0, 32);
        }

        return mb_substr($key, 0, 120);
    }

    /** @return array{queries: int, template_versions: int} */
    private function backfillLineage(bool $catalogVersionCreated): array
    {
        $queryCount = 0;
        $queries = Query::query()
            ->withTrashed()
            ->select(['id', 'parameters']);

        if (! $catalogVersionCreated) {
            $queries->whereDoesntHave('semanticResources');
        }

        foreach ($queries->lazyById() as $query) {
            $definition = $query->parameters ?? [];

            if ($this->lineage->dependencies($definition) === []) {
                continue;
            }

            $this->lineage->syncQuery($query, $definition);
            $queryCount++;
        }

        $templateVersionCount = 0;
        $templateVersions = QueryTemplateVersion::query()
            ->select(['id', 'definition']);

        if (! $catalogVersionCreated) {
            $templateVersions->whereDoesntHave('semanticResources');
        }

        foreach ($templateVersions->lazyById() as $templateVersion) {
            if ($this->lineage->dependencies($templateVersion->definition) === []) {
                continue;
            }

            $this->lineage->syncTemplateVersion($templateVersion, $templateVersion->definition);
            $templateVersionCount++;
        }

        return ['queries' => $queryCount, 'template_versions' => $templateVersionCount];
    }

    /**
     * @param  array<string, int>  $created
     */
    private function incrementIfCreated(object $model, array &$created, string $key): void
    {
        if (($model->wasRecentlyCreated ?? false) === true) {
            $created[$key]++;
        }
    }

    /**
     * @return array{resources: int, resource_translations: int, fields: int, field_translations: int, relations: int, relation_translations: int, glossary_terms: int, glossary_translations: int}
     */
    private function emptyCounts(): array
    {
        return [
            'resources' => 0,
            'resource_translations' => 0,
            'fields' => 0,
            'field_translations' => 0,
            'relations' => 0,
            'relation_translations' => 0,
            'glossary_terms' => 0,
            'glossary_translations' => 0,
        ];
    }
}
