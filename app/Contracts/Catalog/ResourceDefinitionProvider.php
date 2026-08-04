<?php

namespace App\Contracts\Catalog;

use App\Domain\Resource\ResourceDefinition;

/**
 * Contrat d'un fournisseur de ResourceDefinitions.
 *
 * Chaque provider apporte une source de métadonnées (catalogue sémantique,
 * snapshots /describe, overrides techniques, catalogue historique…).
 * Le HybridResourceCatalog agrège leurs contributions avec un merge déterministe.
 *
 * Hiérarchie d'autorité (du plus au moins autoritaire) :
 *   1. Catalogue sémantique publié  → décide ce qui est autorisé, actif, visible
 *   2. Snapshots Oracle /describe   → faits techniques observables
 *   3. Overrides techniques         → chemins, identifiants, bindings non découvrables
 *   4. Fallback catalogue historique → compatibilité pendant la transition
 */
interface ResourceDefinitionProvider
{
    /**
     * Nom lisible du provider, utilisé dans la provenance.
     * Ex: 'oracle_describe', 'semantic_catalog', 'manual_override', 'legacy_fallback'.
     */
    public function name(): string;

    /**
     * Retourne les ResourceDefinitions que ce provider peut fournir pour ce contexte.
     *
     * @return list<ResourceDefinition>
     */
    public function provide(CatalogContext $context): array;

    /**
     * Indique si ce provider prend en charge ce contexte.
     * Permet de court-circuiter l'appel à provide() lorsque le provider
     * est inadapté (ex : snapshot absent du tenant demandé).
     */
    public function supports(CatalogContext $context): bool;

    /**
     * Version stable de la contribution de ce provider.
     *
     * Utilisée pour construire l'empreinte immuable du catalogue hybride.
     * Doit être déterministe et changer si et seulement si les données changent.
     *
     * Format libre mais stable (ex: hash SHA-256, numéro de version sémantique,
     * timestamp UTC ISO 8601 de la dernière modification).
     */
    public function version(CatalogContext $context): string;
}
