<?php

namespace App\Services;

use App\Enums\SemanticCatalogVersionStatus;
use App\Enums\SemanticRelationKind;
use App\Enums\SemanticRelationStatus;
use App\Enums\SemanticSqlMappingStatus;
use App\Models\SemanticCatalogVersion;
use Illuminate\Support\Str;

class SemanticCatalogReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function suggestions(string $locale, ?string $search = null): array
    {
        $catalog = $this->catalog();
        $resources = $this->arrayItems($catalog['resources'] ?? null);
        $resourceIndex = collect($resources)
            ->keyBy(fn (array $resource): string => (string) ($resource['resource_key'] ?? ''));
        $suggestions = [];

        foreach ($resources as $resource) {
            if (($resource['is_active'] ?? false) !== true) {
                continue;
            }

            $translations = $this->translations($resource);
            $label = $this->localizedString($translations, $locale, 'name')
                ?? (string) ($resource['source_name'] ?? $resource['resource_key'] ?? '');
            $description = $this->localizedString($translations, $locale, 'description');
            $synonyms = $this->localizedList($translations, $locale, 'synonyms');
            $fields = array_values(array_filter(
                $this->arrayItems($resource['fields'] ?? null),
                fn (array $field): bool => ($field['is_active'] ?? false) === true,
            ));
            $rootFields = [];
            $childFields = [];
            $localizedFields = [];

            foreach ($fields as $field) {
                $fieldTranslations = $this->translations($field);
                $fieldName = (string) ($field['source_name'] ?? '');
                $childKey = (string) ($field['child_key'] ?? '');
                $fieldPayload = [
                    'source_name' => $fieldName,
                    'child_key' => $childKey,
                    'label' => $this->localizedString($fieldTranslations, $locale, 'name') ?? $fieldName,
                    'description' => $this->localizedString($fieldTranslations, $locale, 'description'),
                    'synonyms' => $this->localizedList($fieldTranslations, $locale, 'synonyms'),
                    'data_type' => $field['data_type'] ?? null,
                    'classification' => $field['classification'] ?? null,
                    'data_category' => $field['data_category'] ?? null,
                    'is_nullable' => $field['is_nullable'] ?? null,
                    'is_updatable' => $field['is_updatable'] ?? null,
                    'sql_mapping_status' => $field['sql_mapping_status'] ?? null,
                ];
                $localizedFields[] = $fieldPayload;

                if ($childKey === '') {
                    $rootFields[] = $fieldName;
                } else {
                    $childFields[$childKey][] = $fieldName;
                }
            }

            $childResources = [];
            $joinKeys = [];
            $localizedRelations = [];

            foreach ($this->arrayItems($resource['relations'] ?? null) as $relation) {
                if (
                    ($relation['is_active'] ?? false) !== true
                    || ($relation['status'] ?? null) !== SemanticRelationStatus::Published->value
                ) {
                    continue;
                }

                $relationTranslations = $this->translations($relation);
                $relationLabel = $this->localizedString($relationTranslations, $locale, 'name')
                    ?? (string) ($relation['target_key'] ?? '');
                $kind = (string) ($relation['kind'] ?? '');
                $targetKey = (string) ($relation['target_key'] ?? '');
                $localizedRelations[] = [
                    'key' => $relation['relation_key'] ?? null,
                    'kind' => $kind,
                    'target_key' => $targetKey,
                    'label' => $relationLabel,
                    'description' => $this->localizedString($relationTranslations, $locale, 'description'),
                    'source_field' => $relation['source_field'] ?? null,
                    'target_field' => $relation['target_field'] ?? null,
                    'cardinality' => $relation['cardinality'] ?? null,
                ];

                if ($kind === SemanticRelationKind::Expand->value) {
                    $childResources[] = $targetKey;
                } elseif ($kind === SemanticRelationKind::Join->value) {
                    $joinKeys[$targetKey] = [
                        'local_key' => $relation['source_field'] ?? null,
                        'remote_key' => $relation['target_field'] ?? null,
                        'label' => $relationLabel,
                    ];
                }
            }

            $sql = $this->sqlDefinition($resource, $resourceIndex->all());
            $supportedRelations = $sql === null
                ? []
                : [...array_keys($sql['child_tables']), ...array_keys($sql['joins'])];
            $suggestion = [
                'key' => $resource['resource_key'] ?? null,
                'source_name' => $resource['source_name'] ?? null,
                'label' => $label,
                'description' => $description,
                'synonyms' => $synonyms,
                'keywords' => $synonyms,
                'domain' => $resource['domain'] ?? null,
                'method' => 'GET',
                'path' => $resource['api_path'] ?? null,
                'classification' => $resource['classification'] ?? null,
                'data_category' => $resource['data_category'] ?? null,
                'fields' => $rootFields,
                'localized_fields' => $localizedFields,
                'preview_fields' => array_slice($rootFields, 0, 4),
                'child_resources' => array_values(array_unique($childResources)),
                'child_fields' => $childFields,
                'join_keys' => $joinKeys,
                'relations' => $localizedRelations,
                'sql_mapping_status' => $resource['sql_mapping_status'] ?? null,
                'bip_available' => $sql !== null,
                'bip_capability' => $sql === null ? 'unavailable' : 'partial',
                'bip_supported_fields' => $sql === null ? [] : array_keys($sql['columns']),
                'bip_supported_relations' => $supportedRelations,
                'sql' => $sql,
            ];

            if ($search === null || $this->matches($suggestion, $search)) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /** @return list<array<string, mixed>> */
    public function glossary(string $locale): array
    {
        $terms = [];

        foreach ($this->arrayItems($this->catalog()['glossary'] ?? null) as $term) {
            if (($term['is_active'] ?? false) !== true) {
                continue;
            }

            $translations = $this->translations($term);
            $terms[] = [
                'key' => $term['term_key'] ?? null,
                'term' => $this->localizedString($translations, $locale, 'term')
                    ?? (string) ($term['term_key'] ?? ''),
                'definition' => $this->localizedString($translations, $locale, 'definition'),
                'synonyms' => $this->localizedList($translations, $locale, 'synonyms'),
                'forbidden_terms' => $this->localizedList($translations, $locale, 'forbidden_terms'),
                'examples' => $this->localizedList($translations, $locale, 'examples'),
                'domain' => $term['domain'] ?? null,
                'classification' => $term['classification'] ?? null,
                'data_category' => $term['data_category'] ?? null,
            ];
        }

        return $terms;
    }

    public function glossaryContext(string $locale): string
    {
        return collect($this->glossary($locale))
            ->map(function (array $term): string {
                $context = '- '.$term['term'];

                if (is_string($term['definition']) && $term['definition'] !== '') {
                    $context .= ' — '.$term['definition'];
                }

                if ($term['synonyms'] !== []) {
                    $context .= ' (synonymes : '.implode(', ', $term['synonyms']).')';
                }

                if ($term['forbidden_terms'] !== []) {
                    $context .= ' (termes à éviter : '.implode(', ', $term['forbidden_terms']).')';
                }

                return $context;
            })
            ->implode("\n");
    }

    public function context(string $locale): string
    {
        $resources = collect($this->suggestions($locale))
            ->map(function (array $resource): string {
                $relations = collect($this->arrayItems($resource['relations'] ?? null))
                    ->map(fn (array $relation): string => $relation['label'])
                    ->implode(', ');
                $line = sprintf(
                    '- %s (clé: %s, domaine: %s) — %s',
                    $resource['label'],
                    $resource['key'],
                    $resource['domain'],
                    $resource['description'] ?? '',
                );
                $line .= "\n  champs: ".implode(', ', $resource['fields']);
                $line .= "\n  relations autorisées: ".($relations === '' ? '(aucune)' : $relations);

                return $line;
            })
            ->implode("\n");
        $glossary = $this->glossaryContext($locale);

        return $glossary === ''
            ? $resources
            : $resources."\n\nGlossaire contrôlé :\n".$glossary;
    }

    public function currentVersion(): ?SemanticCatalogVersion
    {
        return SemanticCatalogVersion::query()
            ->where('status', SemanticCatalogVersionStatus::Published)
            ->where('published_slot', SemanticCatalogVersion::PUBLISHED_SLOT)
            ->first();
    }

    /** @return array<string, mixed> */
    public function catalog(): array
    {
        $version = $this->currentVersion();

        if ($version === null) {
            return [];
        }

        $catalog = $version->getAttribute('catalog');

        return is_array($catalog) ? $catalog : [];
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return list<array<string, mixed>>
     */
    private function translations(array $entity): array
    {
        return $this->arrayItems($entity['translations'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function arrayItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @param list<array<string, mixed>> $translations */
    private function localizedString(
        array $translations,
        string $locale,
        string $key,
    ): ?string {
        foreach (array_unique([$this->locale($locale), 'fr']) as $candidateLocale) {
            foreach ($translations as $translation) {
                $value = $translation['locale'] === $candidateLocale
                    ? ($translation[$key] ?? null)
                    : null;

                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $translations
     * @return list<string>
     */
    private function localizedList(array $translations, string $locale, string $key): array
    {
        foreach (array_unique([$this->locale($locale), 'fr']) as $candidateLocale) {
            foreach ($translations as $translation) {
                $value = $translation['locale'] === $candidateLocale
                    ? ($translation[$key] ?? null)
                    : null;

                if (is_array($value) && $value !== []) {
                    return array_values(array_filter(
                        $value,
                        fn (mixed $item): bool => is_string($item) && trim($item) !== '',
                    ));
                }
            }
        }

        return [];
    }

    /** @param array<string, mixed> $suggestion */
    private function matches(array $suggestion, string $search): bool
    {
        $needle = $this->searchValue($search);

        if ($needle === '') {
            return true;
        }

        $haystacks = [
            $suggestion['key'],
            $suggestion['source_name'],
            $suggestion['label'],
            $suggestion['description'],
            $suggestion['domain'],
            ...$suggestion['synonyms'],
        ];

        foreach ($haystacks as $haystack) {
            if (is_string($haystack) && str_contains($this->searchValue($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, array<string, mixed>>  $resourceIndex
     * @return array{table: string, alias: string|null, columns: array<string, string>, child_tables: array<string, array<string, mixed>>, joins: array<string, array<string, mixed>>}|null
     */
    private function sqlDefinition(array $resource, array $resourceIndex): ?array
    {
        $table = $resource['sql_table'] ?? null;

        if (
            ($resource['sql_mapping_status'] ?? null) !== SemanticSqlMappingStatus::Exact->value
            || ! is_string($table)
            || $table === ''
        ) {
            return null;
        }

        $fields = array_values(array_filter(
            $resource['fields'] ?? [],
            fn (mixed $field): bool => is_array($field)
                && ($field['is_active'] ?? false) === true
                && ($field['child_key'] ?? '') === '',
        ));
        $columns = $this->exactColumns($fields);

        if ($columns === []) {
            return null;
        }

        $childTables = [];
        $joins = [];

        foreach ($resource['relations'] ?? [] as $relation) {
            if (
                ! is_array($relation)
                || ($relation['is_active'] ?? false) !== true
                || ($relation['status'] ?? null) !== SemanticRelationStatus::Published->value
            ) {
                continue;
            }

            $relationTable = $relation['sql_table'] ?? null;
            $relationJoin = $relation['sql_join'] ?? null;

            if (
                ! is_string($relationTable)
                || $relationTable === ''
                || ! is_string($relationJoin)
                || $relationJoin === ''
            ) {
                continue;
            }

            $targetKey = (string) ($relation['target_key'] ?? '');
            $kind = (string) ($relation['kind'] ?? '');

            if ($kind === SemanticRelationKind::Expand->value) {
                $relationFields = array_values(array_filter(
                    $resource['fields'] ?? [],
                    fn (mixed $field): bool => is_array($field)
                        && ($field['is_active'] ?? false) === true
                        && ($field['child_key'] ?? '') === $targetKey,
                ));
            } elseif ($kind === SemanticRelationKind::Join->value) {
                $target = $resourceIndex[$targetKey] ?? null;

                if (! is_array($target) || ($target['is_active'] ?? false) !== true) {
                    continue;
                }

                $relationFields = array_values(array_filter(
                    $target['fields'] ?? [],
                    fn (mixed $field): bool => is_array($field)
                        && ($field['is_active'] ?? false) === true
                        && ($field['child_key'] ?? '') === '',
                ));
            } else {
                continue;
            }

            $relationColumns = $this->exactColumns($relationFields);

            if ($relationColumns === []) {
                continue;
            }

            $definition = [
                'table' => $relationTable,
                'alias' => is_string($relation['sql_alias'] ?? null) ? $relation['sql_alias'] : null,
                'join' => $relationJoin,
                'columns' => $relationColumns,
            ];

            if ($kind === SemanticRelationKind::Expand->value) {
                $childTables[$targetKey] = $definition;
            } else {
                $joins[$targetKey] = $definition;
            }
        }

        return [
            'table' => $table,
            'alias' => is_string($resource['sql_alias'] ?? null) ? $resource['sql_alias'] : null,
            'columns' => $columns,
            'child_tables' => $childTables,
            'joins' => $joins,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function exactColumns(array $fields): array
    {
        $columns = [];

        foreach ($fields as $field) {
            $sourceName = $field['source_name'] ?? null;
            $expression = $field['sql_expression'] ?? null;

            if (
                ($field['sql_mapping_status'] ?? null) !== SemanticSqlMappingStatus::Exact->value
                || ! is_string($sourceName)
                || $sourceName === ''
                || ! is_string($expression)
                || $expression === ''
            ) {
                continue;
            }

            $columns[$sourceName] = $expression;
        }

        return $columns;
    }

    private function searchValue(string $value): string
    {
        return mb_strtolower(Str::ascii(trim($value)));
    }

    private function locale(string $locale): string
    {
        $locale = mb_strtolower(Str::before(str_replace('_', '-', $locale), '-'));

        return in_array($locale, ['fr', 'en', 'es'], true) ? $locale : 'fr';
    }
}
