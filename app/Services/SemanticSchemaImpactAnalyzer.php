<?php

namespace App\Services;

use App\Enums\OracleSchemaImpactKind;
use App\Enums\OracleSchemaImpactSeverity;
use App\Enums\OracleSchemaImpactStatus;
use App\Models\OracleResourceSchemaSnapshot;
use App\Models\OracleSchemaImpact;
use App\Models\QuerySemanticResource;
use App\Models\QueryTemplateVersionSemanticResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SemanticSchemaImpactAnalyzer
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Analyse only the immutable diff captured by the snapshot. An impact is
     * emitted for the drift itself so it can always be acknowledged. Extra
     * consumer impacts require an exact semantic dependency, or a definition
     * intentionally requesting the complete resource (no field-level
     * dependency was captured for that resource).
     */
    public function analyze(User $actor, OracleResourceSchemaSnapshot $snapshot): int
    {
        if ($snapshot->previous_snapshot_id === null) {
            $this->recordAudit($actor, $snapshot, collect(), 0);

            return 0;
        }

        $diff = $this->normalizedDiff($snapshot->diff);
        $queryDependencies = $this->queryDependencies($snapshot);
        $templateDependencies = $this->templateDependencies($snapshot);
        $created = collect();
        $deduplicatedCount = 0;

        foreach ($this->changes($diff) as $change) {
            $genericImpact = $this->persistImpact(
                $snapshot,
                $change['kind'],
                $change['severity'],
                $change['fields'],
                null,
                null,
            );

            if ($genericImpact->wasRecentlyCreated) {
                $created->push($genericImpact);
            } else {
                $deduplicatedCount++;
            }

            $queryFields = $this->affectedOwners(
                $queryDependencies,
                'query_id',
                $change['fields'],
            );
            $templateFields = $this->affectedOwners(
                $templateDependencies,
                'query_template_version_id',
                $change['fields'],
            );

            foreach ($queryFields as $queryId => $affectedFields) {
                $impact = $this->persistImpact(
                    $snapshot,
                    $change['kind'],
                    $change['severity'],
                    $affectedFields,
                    $queryId,
                    null,
                );

                if ($impact->wasRecentlyCreated) {
                    $created->push($impact);
                } else {
                    $deduplicatedCount++;
                }
            }

            foreach ($templateFields as $templateVersionId => $affectedFields) {
                $impact = $this->persistImpact(
                    $snapshot,
                    $change['kind'],
                    $change['severity'],
                    $affectedFields,
                    null,
                    $templateVersionId,
                );

                if ($impact->wasRecentlyCreated) {
                    $created->push($impact);
                } else {
                    $deduplicatedCount++;
                }
            }
        }

        $this->recordAudit($actor, $snapshot, $created, $deduplicatedCount);

        return $created->count();
    }

    /**
     * @return Collection<int, QuerySemanticResource>
     */
    private function queryDependencies(OracleResourceSchemaSnapshot $snapshot): Collection
    {
        return QuerySemanticResource::query()
            ->where('resource_key', $snapshot->resource_key)
            ->where('child_key', $snapshot->child)
            ->whereHas('executedQuery', function (Builder $query) use ($snapshot): void {
                $query->where('oracle_tenant_id', $snapshot->oracle_tenant_id);
            })
            ->get(['query_id', 'field_key']);
    }

    /**
     * @return Collection<int, QueryTemplateVersionSemanticResource>
     */
    private function templateDependencies(OracleResourceSchemaSnapshot $snapshot): Collection
    {
        return QueryTemplateVersionSemanticResource::query()
            ->where('resource_key', $snapshot->resource_key)
            ->where('child_key', $snapshot->child)
            ->get(['query_template_version_id', 'field_key']);
    }

    /**
     * A resource-only lineage row represents a wildcard only when that owner
     * has no field-level dependency for this same resource and child scope.
     *
     * @param  Collection<int, QuerySemanticResource>|Collection<int, QueryTemplateVersionSemanticResource>  $dependencies
     * @param  'query_id'|'query_template_version_id'  $ownerColumn
     * @param  list<string>  $changedFields
     * @return array<int, list<string>>
     */
    private function affectedOwners(
        Collection $dependencies,
        string $ownerColumn,
        array $changedFields,
    ): array {
        /** @var array<int, array{has_resource: bool, fields: array<string, true>}> $owners */
        $owners = [];

        foreach ($dependencies as $dependency) {
            $ownerId = (int) $dependency->getAttribute($ownerColumn);
            $field = trim((string) $dependency->field_key);
            $owners[$ownerId] ??= ['has_resource' => false, 'fields' => []];

            if ($field === '') {
                $owners[$ownerId]['has_resource'] = true;
            } else {
                $owners[$ownerId]['fields'][$this->normalizeField($field)] = true;
            }
        }

        $affected = [];

        foreach ($owners as $ownerId => $dependency) {
            $exactFields = array_values(array_filter(
                $changedFields,
                fn (string $field): bool => isset(
                    $dependency['fields'][$this->normalizeField($field)],
                ),
            ));

            if ($exactFields !== []) {
                $affected[$ownerId] = $exactFields;

                continue;
            }

            if ($dependency['has_resource'] && $dependency['fields'] === []) {
                $affected[$ownerId] = $changedFields;
            }
        }

        return $affected;
    }

    /**
     * @param  list<string>  $affectedFields
     */
    private function persistImpact(
        OracleResourceSchemaSnapshot $snapshot,
        OracleSchemaImpactKind $kind,
        OracleSchemaImpactSeverity $severity,
        array $affectedFields,
        ?int $queryId,
        ?int $templateVersionId,
    ): OracleSchemaImpact {
        sort($affectedFields, SORT_STRING);
        $identity = [
            'oracle_tenant_id' => $snapshot->oracle_tenant_id,
            'snapshot_id' => $snapshot->id,
            'semantic_catalog_version_id' => $snapshot->semantic_catalog_version_id,
            'query_id' => $queryId,
            'query_template_version_id' => $templateVersionId,
            'resource_key' => $snapshot->resource_key,
            'child_key' => $snapshot->child,
            'kind' => $kind->value,
            'affected_fields' => $affectedFields,
        ];
        $fingerprint = hash('sha256', json_encode(
            $identity,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return OracleSchemaImpact::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'oracle_resource_schema_snapshot_id' => $snapshot->id,
                'oracle_tenant_id' => $snapshot->oracle_tenant_id,
                'semantic_catalog_version_id' => $snapshot->semantic_catalog_version_id,
                'query_id' => $queryId,
                'query_template_version_id' => $templateVersionId,
                'resource_key' => $snapshot->resource_key,
                'child_key' => $snapshot->child,
                'kind' => $kind,
                'severity' => $severity,
                'status' => OracleSchemaImpactStatus::Open,
                'affected_fields' => $affectedFields,
                'detected_at' => now(),
            ],
        );
    }

    /**
     * @param  array{added: list<string>, removed: list<string>, changed: list<string>}  $diff
     * @return list<array{kind: OracleSchemaImpactKind, severity: OracleSchemaImpactSeverity, fields: list<string>}>
     */
    private function changes(array $diff): array
    {
        return array_values(array_filter([
            [
                'kind' => OracleSchemaImpactKind::FieldAdded,
                'severity' => OracleSchemaImpactSeverity::Informational,
                'fields' => $diff['added'],
            ],
            [
                'kind' => OracleSchemaImpactKind::FieldRemoved,
                'severity' => OracleSchemaImpactSeverity::Breaking,
                'fields' => $diff['removed'],
            ],
            [
                'kind' => OracleSchemaImpactKind::FieldChanged,
                'severity' => OracleSchemaImpactSeverity::Review,
                'fields' => $diff['changed'],
            ],
        ], fn (array $change): bool => $change['fields'] !== []));
    }

    /**
     * @param  array<string, mixed>  $diff
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function normalizedDiff(array $diff): array
    {
        return [
            'added' => $this->fieldList($diff['added'] ?? []),
            'removed' => $this->fieldList($diff['removed'] ?? []),
            'changed' => $this->fieldList($diff['changed'] ?? []),
        ];
    }

    /** @return list<string> */
    private function fieldList(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            fn (mixed $field): string => trim((string) $field),
            $fields,
        ))));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function normalizeField(string $field): string
    {
        return mb_strtolower(trim($field));
    }

    /**
     * @param  Collection<int, OracleSchemaImpact>  $created
     */
    private function recordAudit(
        User $actor,
        OracleResourceSchemaSnapshot $snapshot,
        Collection $created,
        int $deduplicatedCount,
    ): void {
        $this->audit->record($actor, 'oracle.schema_impacts_analyzed', $snapshot, [
            'tenant_id' => $snapshot->oracle_tenant_id,
            'snapshot_id' => $snapshot->id,
            'catalog_version_id' => $snapshot->semantic_catalog_version_id,
            'resource_key' => $snapshot->resource_key,
            'impact_count' => $created->count(),
            'generic_impact_count' => $created
                ->whereNull('query_id')
                ->whereNull('query_template_version_id')
                ->count(),
            'query_impact_count' => $created->whereNotNull('query_id')->count(),
            'template_version_impact_count' => $created
                ->whereNotNull('query_template_version_id')
                ->count(),
            'breaking_count' => $created
                ->where('severity', OracleSchemaImpactSeverity::Breaking)
                ->count(),
            'review_count' => $created
                ->where('severity', OracleSchemaImpactSeverity::Review)
                ->count(),
            'informational_count' => $created
                ->where('severity', OracleSchemaImpactSeverity::Informational)
                ->count(),
            'deduplicated_count' => $deduplicatedCount,
        ]);
    }
}
