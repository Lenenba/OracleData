<?php

namespace App\Services;

use App\Enums\SemanticCatalogVersionStatus;
use App\Enums\SemanticLineageUsage;
use App\Models\Query;
use App\Models\QuerySemanticResource;
use App\Models\QueryTemplateVersion;
use App\Models\QueryTemplateVersionSemanticResource;
use App\Models\SemanticCatalogVersion;
use App\Models\SemanticResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SemanticLineageService
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function syncQuery(Query $query, array $definition): void
    {
        $this->sync(
            QuerySemanticResource::class,
            'query_id',
            $query->id,
            $definition,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function syncTemplateVersion(QueryTemplateVersion $version, array $definition): void
    {
        $this->sync(
            QueryTemplateVersionSemanticResource::class,
            'query_template_version_id',
            $version->id,
            $definition,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{catalog_version_id: int|null, lineage: list<array<string, mixed>>}
     */
    public function executionSnapshot(
        ?Query $query,
        ?QueryTemplateVersion $templateVersion,
        array $payload,
    ): array {
        $catalogVersion = $this->publishedVersion();
        $lineage = [];
        $calls = $payload['oracleCalls'] ?? [];

        if (is_array($calls) && $calls !== []) {
            foreach ($calls as $index => $call) {
                if (! is_array($call)) {
                    continue;
                }

                $definition = [
                    'resource_key' => (string) ($call['resource'] ?? ''),
                    ...Arr::only((array) ($call['params'] ?? []), [
                        'fields', 'expand', 'joins', 'child_fields', 'q', 'orderBy',
                    ]),
                ];

                foreach ($this->dependencies($definition) as $dependency) {
                    $lineage[] = [
                        ...$dependency,
                        'call_index' => $index,
                        'rows_count' => max(0, (int) ($call['count'] ?? 0)),
                    ];
                }
            }
        } elseif ($query !== null) {
            $lineage = $query->semanticResources()
                ->orderBy('id')
                ->get()
                ->map(fn (QuerySemanticResource $dependency): array => $this->serializeDependency($dependency))
                ->all();
        } elseif ($templateVersion !== null) {
            $lineage = $templateVersion->semanticResources()
                ->orderBy('id')
                ->get()
                ->map(fn (QueryTemplateVersionSemanticResource $dependency): array => $this->serializeDependency($dependency))
                ->all();
        }

        return [
            'catalog_version_id' => $catalogVersion?->id,
            'lineage' => array_values($lineage),
        ];
    }

    /**
     * @param  class-string<QuerySemanticResource|QueryTemplateVersionSemanticResource>  $model
     * @param  array<string, mixed>  $definition
     */
    private function sync(string $model, string $foreignKey, int $ownerId, array $definition): void
    {
        DB::transaction(function () use ($model, $foreignKey, $ownerId, $definition): void {
            $catalogVersion = $this->publishedVersion();
            $dependencies = $this->dependencies($definition);
            $hash = hash('sha256', json_encode(
                $this->canonicalize($definition),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $resourceIds = SemanticResource::query()
                ->whereIn('resource_key', array_unique(array_column($dependencies, 'resource_key')))
                ->pluck('id', 'resource_key');

            $model::query()->where($foreignKey, $ownerId)->delete();

            foreach ($dependencies as $dependency) {
                $model::query()->create([
                    $foreignKey => $ownerId,
                    'semantic_resource_id' => $resourceIds->get($dependency['resource_key']),
                    'semantic_catalog_version_id' => $catalogVersion?->id,
                    ...$dependency,
                    'definition_hash' => $hash,
                    'captured_at' => now(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{resource_key: string, child_key: string, field_key: string, usage: string}>
     */
    public function dependencies(array $definition): array
    {
        $parameters = is_array($definition['parameters'] ?? null)
            ? $definition['parameters']
            : $definition;
        $resourceKey = trim((string) ($parameters['resource_key'] ?? $definition['resource_key'] ?? ''));

        if ($resourceKey === '') {
            return [];
        }

        $dependencies = [[
            'resource_key' => $resourceKey,
            'child_key' => '',
            'field_key' => '',
            'usage' => SemanticLineageUsage::Resource->value,
        ]];

        foreach ($this->list($parameters['fields'] ?? []) as $field) {
            $dependencies[] = $this->dependency($resourceKey, '', $field, SemanticLineageUsage::Select);
        }

        foreach ($this->filterFields((string) ($parameters['q'] ?? '')) as $field) {
            $dependencies[] = $this->dependency($resourceKey, '', $field, SemanticLineageUsage::Filter);
        }

        foreach ($this->orderFields((string) ($parameters['orderBy'] ?? '')) as $field) {
            $dependencies[] = $this->dependency($resourceKey, '', $field, SemanticLineageUsage::Sort);
        }

        $childFields = is_array($parameters['child_fields'] ?? null)
            ? $parameters['child_fields']
            : [];

        foreach ($this->list($parameters['expand'] ?? []) as $child) {
            $dependencies[] = $this->dependency($resourceKey, $child, '', SemanticLineageUsage::Expand);

            foreach ($this->list($childFields[$child] ?? []) as $field) {
                $dependencies[] = $this->dependency($resourceKey, $child, $field, SemanticLineageUsage::Select);
            }
        }

        foreach ($this->list($parameters['joins'] ?? []) as $target) {
            $dependencies[] = $this->dependency($resourceKey, $target, '', SemanticLineageUsage::JoinSource);
            $dependencies[] = $this->dependency($target, '', '', SemanticLineageUsage::JoinTarget);

            foreach ($this->list($childFields[$target] ?? []) as $field) {
                $dependencies[] = $this->dependency($target, '', $field, SemanticLineageUsage::Select);
            }
        }

        $unique = [];

        foreach ($dependencies as $dependency) {
            $key = implode('|', $dependency);
            $unique[$key] = $dependency;
        }

        return array_values($unique);
    }

    /**
     * @return array{resource_key: string, child_key: string, field_key: string, usage: string}
     */
    private function dependency(
        string $resourceKey,
        string $childKey,
        string $fieldKey,
        SemanticLineageUsage $usage,
    ): array {
        return [
            'resource_key' => $resourceKey,
            'child_key' => $childKey,
            'field_key' => $fieldKey,
            'usage' => $usage->value,
        ];
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $items,
        ))));
    }

    /** @return list<string> */
    private function filterFields(string $filter): array
    {
        preg_match_all(
            '/(?:^|[;(]|\bAND\b|\bOR\b)\s*([A-Za-z][A-Za-z0-9_]*)\s*(?:!=|>=|<=|=|>|<|LIKE\b|IN\b|BETWEEN\b)/i',
            $filter,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    private function orderFields(string $orderBy): array
    {
        return array_values(array_filter(array_map(
            fn (string $part): string => trim(explode(':', $part, 2)[0]),
            explode(',', $orderBy),
        )));
    }

    private function publishedVersion(): ?SemanticCatalogVersion
    {
        return SemanticCatalogVersion::query()
            ->where('status', SemanticCatalogVersionStatus::Published)
            ->where('published_slot', SemanticCatalogVersion::PUBLISHED_SLOT)
            ->first();
    }

    /** @return array<string, mixed> */
    private function serializeDependency(QuerySemanticResource|QueryTemplateVersionSemanticResource $dependency): array
    {
        $usage = $dependency->getAttribute('usage');

        if (! $usage instanceof SemanticLineageUsage) {
            throw new UnexpectedValueException('La dépendance sémantique contient un type d’usage invalide.');
        }

        return [
            'resource_key' => $dependency->resource_key,
            'child_key' => $dependency->child_key,
            'field_key' => $dependency->field_key,
            'usage' => $usage->value,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);

        if (array_is_list($canonical)) {
            sort($canonical);
        } else {
            ksort($canonical);
        }

        return $canonical;
    }
}
