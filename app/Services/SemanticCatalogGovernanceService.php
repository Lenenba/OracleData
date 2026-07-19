<?php

namespace App\Services;

use App\Enums\SemanticRelationKind;
use App\Enums\SemanticRelationStatus;
use App\Models\SemanticField;
use App\Models\SemanticGlossaryTerm;
use App\Models\SemanticRelation;
use App\Models\SemanticResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SemanticCatalogGovernanceService
{
    /** @var list<string> */
    private const array LOCALES = ['fr', 'en', 'es'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OracleResourceCatalog $oracleCatalog,
        private readonly SemanticCatalogVersioner $versioner,
    ) {}

    /** @param array<string, mixed> $data */
    public function updateResource(SemanticResource $resource, User $actor, array $data): SemanticResource
    {
        return DB::transaction(function () use ($resource, $actor, $data): SemanticResource {
            $locked = SemanticResource::query()->lockForUpdate()->findOrFail($resource->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $this->assertLock($locked->lock_version, (int) $data['lock_version']);
            $before = $locked->only([
                'business_owner_user_id', 'technical_owner_user_id', 'classification',
                'data_category', 'is_active', 'mapping_notes',
            ]);

            $locked->fill(Arr::only($data, [
                'business_owner_user_id', 'technical_owner_user_id', 'classification',
                'data_category', 'is_active', 'mapping_notes',
            ]));
            $locked->lock_version++;
            $locked->save();
            $this->syncTranslations($locked->translations(), (array) $data['translations']);
            $version = $this->versioner->capture($actor, "Ressource {$locked->resource_key} mise à jour")['version'];

            $this->audit->record($actor, 'semantic.resource_updated', $locked, [
                'resource_key' => $locked->resource_key,
                'before' => $before,
                'catalog_version_id' => $version->id,
            ]);

            return $locked->fresh(['translations']) ?? $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateField(
        SemanticResource $resource,
        SemanticField $field,
        User $actor,
        array $data,
    ): SemanticField {
        return DB::transaction(function () use ($resource, $field, $actor, $data): SemanticField {
            $locked = SemanticField::query()
                ->whereBelongsTo($resource, 'semanticResource')
                ->lockForUpdate()
                ->findOrFail($field->id);
            Gate::forUser($actor)->authorize('manageFields', $resource);
            $this->assertLock($locked->lock_version, (int) $data['lock_version']);
            $before = $locked->only(['classification', 'data_category', 'is_active']);
            $locked->fill(Arr::only($data, ['classification', 'data_category', 'is_active']));
            $locked->lock_version++;
            $locked->save();
            $this->syncTranslations($locked->translations(), (array) $data['translations']);
            $version = $this->versioner->capture(
                $actor,
                "Champ {$resource->resource_key}.{$locked->source_name} mis à jour",
            )['version'];

            $this->audit->record($actor, 'semantic.field_updated', $locked, [
                'resource_key' => $resource->resource_key,
                'field_key' => $locked->source_name,
                'before' => $before,
                'catalog_version_id' => $version->id,
            ]);

            return $locked->fresh(['translations']) ?? $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function createRelation(SemanticResource $resource, User $actor, array $data): SemanticRelation
    {
        return DB::transaction(function () use ($resource, $actor, $data): SemanticRelation {
            $source = SemanticResource::query()->lockForUpdate()->findOrFail($resource->id);
            Gate::forUser($actor)->authorize('manageRelations', $source);
            $mapping = $this->relationSqlMapping(
                $source->resource_key,
                (string) $data['kind'],
                (string) $data['target_key'],
            );
            $relation = SemanticRelation::query()->create([
                ...Arr::only($data, [
                    'target_resource_id', 'relation_key', 'kind', 'target_key',
                    'source_field', 'target_field', 'cardinality',
                ]),
                'source_resource_id' => $source->id,
                'sql_table' => $mapping['table'] ?? null,
                'sql_alias' => $mapping['alias'] ?? null,
                'sql_join' => $mapping['join'] ?? null,
                'status' => SemanticRelationStatus::Draft,
                'is_active' => true,
                'lock_version' => 1,
            ]);
            $this->syncTranslations($relation->translations(), (array) $data['translations']);
            $version = $this->versioner->capture(
                $actor,
                "Relation {$relation->relation_key} créée",
            )['version'];

            $this->audit->record($actor, 'semantic.relation_created', $relation, [
                'resource_key' => $source->resource_key,
                'relation_key' => $relation->relation_key,
                'catalog_version_id' => $version->id,
            ]);

            return $relation->fresh(['translations']) ?? $relation;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateRelation(
        SemanticResource $resource,
        SemanticRelation $relation,
        User $actor,
        array $data,
    ): SemanticRelation {
        return DB::transaction(function () use ($resource, $relation, $actor, $data): SemanticRelation {
            $locked = SemanticRelation::query()
                ->where('source_resource_id', $resource->id)
                ->lockForUpdate()
                ->findOrFail($relation->id);
            Gate::forUser($actor)->authorize('manageRelations', $resource);
            $this->assertLock($locked->lock_version, (int) $data['lock_version']);
            $before = $locked->only(['cardinality', 'status']);
            $locked->fill(Arr::only($data, ['cardinality', 'status']));
            $locked->lock_version++;
            $locked->save();
            $this->syncTranslations($locked->translations(), (array) $data['translations']);
            $version = $this->versioner->capture(
                $actor,
                "Relation {$locked->relation_key} mise à jour",
            )['version'];

            $this->audit->record($actor, 'semantic.relation_updated', $locked, [
                'resource_key' => $resource->resource_key,
                'relation_key' => $locked->relation_key,
                'before' => $before,
                'catalog_version_id' => $version->id,
            ]);

            return $locked->fresh(['translations']) ?? $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function upsertGlossary(
        ?SemanticGlossaryTerm $term,
        User $actor,
        array $data,
    ): SemanticGlossaryTerm {
        return DB::transaction(function () use ($term, $actor, $data): SemanticGlossaryTerm {
            if ($term === null) {
                Gate::forUser($actor)->authorize('create', SemanticGlossaryTerm::class);
                $locked = new SemanticGlossaryTerm;
                $locked->lock_version = 1;
                $action = 'created';
            } else {
                $locked = SemanticGlossaryTerm::query()->lockForUpdate()->findOrFail($term->id);
                Gate::forUser($actor)->authorize('update', $locked);
                $this->assertLock($locked->lock_version, (int) $data['lock_version']);
                $locked->lock_version++;
                $action = 'updated';
            }

            $locked->fill(Arr::only($data, [
                'term_key', 'domain', 'classification', 'data_category', 'is_active',
            ]));
            $locked->save();
            $this->syncTranslations($locked->translations(), (array) $data['translations']);
            $version = $this->versioner->capture(
                $actor,
                "Terme {$locked->term_key} {$action}",
            )['version'];

            $this->audit->record($actor, "semantic.glossary_{$action}", $locked, [
                'term_key' => $locked->term_key,
                'catalog_version_id' => $version->id,
            ]);

            return $locked->fresh(['translations']) ?? $locked;
        });
    }

    private function assertLock(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new ConflictHttpException(__('Ces métadonnées ont changé. Rechargez la page avant de réessayer.'));
        }
    }

    /**
     * @param  HasMany<Model, *>  $relation
     * @param  array<string, mixed>  $translations
     */
    private function syncTranslations($relation, array $translations): void
    {
        foreach (self::LOCALES as $locale) {
            $payload = (array) ($translations[$locale] ?? []);
            $relation->updateOrCreate(
                ['locale' => $locale],
                Arr::except($payload, ['locale']),
            );
        }
    }

    /** @return array<string, mixed> */
    private function relationSqlMapping(string $resourceKey, string $kind, string $targetKey): array
    {
        $resource = $this->oracleCatalog->find($resourceKey);

        if ($resource === null) {
            return [];
        }

        $sql = (array) ($resource['sql'] ?? []);

        return $kind === SemanticRelationKind::Expand->value
            ? (array) data_get($sql, "child_tables.{$targetKey}", [])
            : (array) data_get($sql, "joins.{$targetKey}", []);
    }
}
