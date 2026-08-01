<?php

namespace App\Services;

use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\ResourceDefinition;
use App\Services\Workers\WorkersResourceRegistry;

/**
 * Registre central de toutes les ResourceDefinitions.
 *
 * Point unique de lookup pour le Query Builder.
 * Chaque module (HCM, Procurement, Financials…) enregistre ses definitions ici.
 *
 * Actuellement peuplé manuellement par les registries de modules.
 * Plus tard, OracleMetadataProvider pourra enrichir ce registre
 * avec des définitions découvertes dynamiquement.
 *
 * @see WorkersResourceRegistry  Preuve de concept Workers
 */
class ResourceDefinitionRegistry
{
    /** @var array<string, ResourceDefinition>  [id → ResourceDefinition] */
    private array $resources = [];

    /** @var array<string, RelationDefinition>  [id → RelationDefinition] */
    private array $relations = [];

    public function __construct(WorkersResourceRegistry $workers)
    {
        foreach ($workers->all() as $def) {
            $this->register($def);
        }
    }

    /** Enregistre une ResourceDefinition et toutes ses relations déclarées. */
    public function register(ResourceDefinition $definition): void
    {
        $this->resources[$definition->id] = $definition;

        foreach ($definition->relations as $relation) {
            $this->relations[$relation->id] = $relation;
        }
    }

    /** Retourne une ResourceDefinition par son id, ou null. */
    public function find(string $id): ?ResourceDefinition
    {
        return $this->resources[$id] ?? null;
    }

    /** Retourne une ResourceDefinition ou lève une exception. */
    public function findOrFail(string $id): ResourceDefinition
    {
        return $this->resources[$id]
            ?? throw new \RuntimeException("ResourceDefinition '{$id}' not found in registry.");
    }

    /** Retourne une RelationDefinition par son id, ou null. */
    public function findRelation(string $id): ?RelationDefinition
    {
        return $this->relations[$id] ?? null;
    }

    /**
     * Retourne toutes les ResourceDefinitions d'un module donné.
     *
     * @return list<ResourceDefinition>
     */
    public function forModule(string $module): array
    {
        return array_values(array_filter(
            $this->resources,
            fn (ResourceDefinition $r) => $r->module === $module,
        ));
    }

    /**
     * Retourne les enfants directs (CHILD) disponibles pour une ressource.
     *
     * @return list<ResourceDefinition>
     */
    public function childrenOf(string $resourceId): array
    {
        $parent = $this->find($resourceId);

        if ($parent === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (RelationDefinition $rel) => $this->find($rel->targetId),
            $parent->children(),
        )));
    }

    /** @return list<ResourceDefinition> */
    public function all(): array
    {
        return array_values($this->resources);
    }

    /** @return list<RelationDefinition> */
    public function allRelations(): array
    {
        return array_values($this->relations);
    }
}
