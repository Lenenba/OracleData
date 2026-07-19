<?php

namespace App\Services;

use App\Models\OracleResourceField;
use App\Models\OracleResourceSchemaSnapshot;
use App\Models\OracleTenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OracleSchemaSynchronizationService
{
    public function __construct(
        private readonly FusionManager $fusion,
        private readonly OracleResourceCatalog $catalog,
        private readonly OracleDescribeNormalizer $normalizer,
        private readonly SemanticCatalogEnricher $semanticEnricher,
        private readonly SemanticCatalogReader $semanticCatalog,
        private readonly SemanticSchemaImpactAnalyzer $impactAnalyzer,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{status: 'created'|'unchanged'|'changed', resource: OracleResourceField, snapshot: OracleResourceSchemaSnapshot|null}
     */
    public function synchronize(User $actor, OracleTenant $tenant, string $resourceKey): array
    {
        if ($tenant->user_id !== $actor->id) {
            throw new AuthorizationException('Ce tenant Oracle ne vous appartient pas.');
        }

        $catalogResource = $this->catalog->find($resourceKey);

        if ($catalogResource === null) {
            throw new InvalidArgumentException("Ressource Oracle inconnue : [{$resourceKey}].");
        }

        $payload = $this->fusion
            ->forUser($actor)
            ->tenant($tenant->key)
            ->get(rtrim($catalogResource['path'], '/').'/describe');
        $oracleResourceName = Str::afterLast($catalogResource['path'], '/');
        $normalized = $this->normalizer->normalize($payload, $oracleResourceName);

        return DB::transaction(function () use ($actor, $tenant, $resourceKey, $normalized): array {
            $current = OracleResourceField::query()
                ->whereBelongsTo($tenant, 'oracleTenant')
                ->where('resource_key', $resourceKey)
                ->where('child', '')
                ->lockForUpdate()
                ->first();
            $previousSnapshot = OracleResourceSchemaSnapshot::query()
                ->whereBelongsTo($tenant, 'oracleTenant')
                ->where('resource_key', $resourceKey)
                ->where('child', '')
                ->latest('synced_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $status = $previousSnapshot === null
                ? 'created'
                : ($previousSnapshot->schema_hash === $normalized['schema_hash'] ? 'unchanged' : 'changed');

            if ($current === null) {
                $current = new OracleResourceField([
                    'oracle_tenant_id' => $tenant->id,
                    'resource_key' => $resourceKey,
                    'child' => '',
                ]);
            }

            $current->fill([
                'fields' => $normalized['fields'],
                'source' => 'describe',
                'title' => $normalized['title'],
                'attributes' => $normalized['attributes'],
                'schema_hash' => $normalized['schema_hash'],
                'discovered_at' => now(),
            ])->save();
            $catalogVersion = $this->semanticEnricher->enrich(
                $actor,
                $resourceKey,
                $normalized['attributes'],
            ) ?? $this->semanticCatalog->currentVersion();

            $snapshot = null;

            if ($status !== 'unchanged') {
                $previousAttributes = $previousSnapshot === null ? [] : $previousSnapshot->attributes;
                $diff = $this->diff($previousAttributes, $normalized['attributes']);
                $snapshot = OracleResourceSchemaSnapshot::query()->create([
                    'oracle_tenant_id' => $tenant->id,
                    'previous_snapshot_id' => $previousSnapshot?->id,
                    'triggered_by_user_id' => $actor->id,
                    'semantic_catalog_version_id' => $catalogVersion?->id,
                    'resource_key' => $resourceKey,
                    'child' => '',
                    'source' => 'describe',
                    'title' => $normalized['title'],
                    'fields' => $normalized['fields'],
                    'attributes' => $normalized['attributes'],
                    'schema_hash' => $normalized['schema_hash'],
                    'diff' => $diff,
                    'synced_at' => now(),
                ]);
                $this->impactAnalyzer->analyze($actor, $snapshot);
            } else {
                $diff = ['added' => [], 'removed' => [], 'changed' => []];
            }

            $this->audit->record($actor, 'oracle.schema_synchronized', $current, [
                'tenant_id' => $tenant->id,
                'resource_key' => $resourceKey,
                'status' => $status,
                'added_count' => count($diff['added']),
                'removed_count' => count($diff['removed']),
                'changed_count' => count($diff['changed']),
            ]);

            return ['status' => $status, 'resource' => $current, 'snapshot' => $snapshot];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $previousAttributes
     * @param  list<array<string, mixed>>  $currentAttributes
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function diff(array $previousAttributes, array $currentAttributes): array
    {
        $previous = $this->attributesByName($previousAttributes);
        $current = $this->attributesByName($currentAttributes);
        $added = array_values(array_diff(array_keys($current), array_keys($previous)));
        $removed = array_values(array_diff(array_keys($previous), array_keys($current)));
        $changed = [];

        foreach (array_intersect(array_keys($current), array_keys($previous)) as $name) {
            if ($current[$name] !== $previous[$name]) {
                $changed[] = $name;
            }
        }

        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($changed, SORT_STRING);

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, array<string, mixed>>
     */
    private function attributesByName(array $attributes): array
    {
        $indexed = [];

        foreach ($attributes as $attribute) {
            $name = $attribute['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $indexed[$name] = $attribute;
            }
        }

        return $indexed;
    }
}
