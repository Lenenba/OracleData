<?php

namespace App\Contracts\Catalog;

use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\ResourceDefinition;

/**
 * Contrat unique d'accès au Resource Graph canonique.
 *
 * Le moteur de requêtes, le Query Builder et tout nouveau consommateur
 * dépendent de cette interface — jamais d'un tableau particulier, d'Eloquent
 * ou d'un registre interne.
 */
interface ResourceCatalog
{
    /**
     * Retourne une ResourceDefinition par son identifiant canonique, ou null.
     */
    public function find(string $id, CatalogContext $context): ?ResourceDefinition;

    /**
     * Retourne une RelationDefinition par son identifiant, ou null.
     */
    public function findRelation(string $id, CatalogContext $context): ?RelationDefinition;

    /**
     * Retourne les enfants directs (CHILD) disponibles pour une ressource.
     *
     * @return list<ResourceDefinition>
     */
    public function childrenOf(string $resourceId, CatalogContext $context): array;

    /**
     * Retourne toutes les ressources racines accessibles dans ce contexte.
     *
     * @return list<ResourceDefinition>
     */
    public function roots(CatalogContext $context): array;
}
