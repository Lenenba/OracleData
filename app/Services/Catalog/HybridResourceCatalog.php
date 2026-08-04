<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\CatalogContext;
use App\Contracts\Catalog\ResourceCatalog;
use App\Contracts\Catalog\ResourceDefinitionProvider;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\ResourceDefinition;

/**
 * Catalogue canonique hybride alimenté par plusieurs providers.
 *
 * Principe de merge déterministe :
 *   1. Chaque provider supporté est consulté dans l'ordre d'enregistrement.
 *   2. La première définition trouvée pour un ID canonique est retenue.
 *      (Le provider le plus autoritaire est enregistré en premier.)
 *   3. Un conflit critique (même ID, paths différents) échoue de manière fermée.
 *   4. Chaque valeur retenue porte la provenance de son provider.
 *
 * Ordre d'autorité attendu lors de l'enregistrement :
 *   1. Catalogue sémantique publié  → ResourceCatalog::roots() = frontière d'autorisation
 *   2. Overrides techniques (Workers, etc.)
 *   3. Snapshots Oracle /describe
 *   4. Catalogue legacy (fallback)
 *
 * Fermeture pour l'authoring/exécution de graphe :
 *   Lorsque le CatalogContext ne porte pas de version sémantique publiée,
 *   roots() et childrenOf() retournent des listes vides et find() retourne null.
 *   Le moteur historique n'est pas concerné par cette fermeture.
 */
final class HybridResourceCatalog implements ResourceCatalog
{
    /** @var list<ResourceDefinitionProvider> */
    private array $providers = [];

    public function addProvider(ResourceDefinitionProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ResourceCatalog contract
    // ──────────────────────────────────────────────────────────────────────────

    public function find(string $id, CatalogContext $context): ?ResourceDefinition
    {
        return $this->buildIndex($context)[$id] ?? null;
    }

    public function findRelation(string $id, CatalogContext $context): ?RelationDefinition
    {
        return $this->buildRelationIndex($context)[$id] ?? null;
    }

    /**
     * @return list<ResourceDefinition>
     */
    public function childrenOf(string $resourceId, CatalogContext $context): array
    {
        $index = $this->buildIndex($context);
        $resource = $index[$resourceId] ?? null;

        if ($resource === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (RelationDefinition $rel) => $index[$rel->targetId] ?? null,
            $resource->children(),
        )));
    }

    /**
     * Retourne toutes les ressources racines (celles sans parent dans le graphe).
     *
     * Une ressource est "racine" si aucune autre ressource déclarée dans
     * le catalogue n'a une relation CHILD pointant vers elle.
     *
     * Retourne une liste vide lorsque le contexte n'a pas de version sémantique publiée,
     * bloquant ainsi l'authoring et l'exécution de graphe.
     *
     * @return list<ResourceDefinition>
     */
    public function roots(CatalogContext $context): array
    {
        if (! $context->hasPublishedSemanticVersion()) {
            return [];
        }

        $index = $this->buildIndex($context);
        $childIds = [];

        foreach ($index as $def) {
            foreach ($def->children() as $relation) {
                $childIds[$relation->targetId] = true;
            }
        }

        return array_values(array_filter(
            $index,
            fn (ResourceDefinition $def): bool => ! isset($childIds[$def->id]),
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fingerprint
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Calcule l'empreinte immuable du catalogue hybride pour ce contexte.
     *
     * Les composants sont : provider:{name} → version du provider.
     * L'empreinte change si et seulement si la version d'un provider change.
     */
    public function fingerprint(CatalogContext $context): ResourceCatalogFingerprint
    {
        $components = [];

        foreach ($this->providers as $provider) {
            if ($provider->supports($context)) {
                $components['provider:'.$provider->name()] = $provider->version($context);
            }
        }

        // Ajouter la version sémantique si disponible.
        if ($context->hasPublishedSemanticVersion()) {
            $components['semantic_version'] = (string) $context->semanticVersion;
        }

        $components['api_family'] = $context->apiFamily;
        $components['api_version'] = $context->apiVersion;

        return ResourceCatalogFingerprint::fromComponents($components);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // IdentityMap
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Construit la table de correspondance des identités pour ce contexte.
     *
     * Enregistre automatiquement : clé historique (name) → ID canonique.
     */
    public function identityMap(CatalogContext $context): ResourceIdentityMap
    {
        $map = new ResourceIdentityMap;
        $index = $this->buildIndex($context);

        foreach ($index as $id => $def) {
            // Passthrough : ID canonique → lui-même.
            $map->register($id, $id);
            // Clé historique (nom Oracle court) → ID canonique.
            if ($def->name !== $id) {
                $map->register($def->name, $id);
            }
        }

        return $map;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Provenance
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Retourne la liste des provenances pour un contexte donné.
     * Utile pour l'audit et les diagnostics.
     *
     * @return list<ResourceProvenance>
     */
    public function provenances(CatalogContext $context): array
    {
        $result = [];
        $seen = [];

        foreach ($this->providers as $provider) {
            if (! $provider->supports($context)) {
                continue;
            }

            foreach ($provider->provide($context) as $definition) {
                if (isset($seen[$definition->id])) {
                    continue;
                }

                $seen[$definition->id] = true;
                $result[] = new ResourceProvenance(
                    providerName: $provider->name(),
                    providerVersion: $provider->version($context),
                    definition: $definition,
                );
            }
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal merge
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Construit et retourne l'index [id → ResourceDefinition] fusionné.
     *
     * Merge déterministe :
     *   - Premier provider qui définit un ID = autorité pour cet ID.
     *   - Conflit critique (même ID, collectionPath différent) = RuntimeException.
     *
     * @return array<string, ResourceDefinition>
     */
    private function buildIndex(CatalogContext $context): array
    {
        $index = [];

        foreach ($this->providers as $provider) {
            if (! $provider->supports($context)) {
                continue;
            }

            foreach ($provider->provide($context) as $definition) {
                $id = $definition->id;

                if (! isset($index[$id])) {
                    $index[$id] = $definition;

                    continue;
                }

                // Conflit critique : même ID, chemin de collection différent.
                $existing = $index[$id];
                if (
                    $existing->collectionPath !== $definition->collectionPath
                    && $existing->collectionPath !== ''
                    && $definition->collectionPath !== ''
                ) {
                    throw new \RuntimeException(
                        "HybridResourceCatalog: critical conflict for resource '{$id}': "
                        ."collectionPath '{$existing->collectionPath}' (existing) "
                        ."vs '{$definition->collectionPath}' (provider '{$provider->name()}').",
                    );
                }
            }
        }

        return $index;
    }

    /**
     * Construit l'index [id → RelationDefinition] depuis toutes les ressources fusionnées.
     *
     * @return array<string, RelationDefinition>
     */
    private function buildRelationIndex(CatalogContext $context): array
    {
        $relations = [];

        foreach ($this->buildIndex($context) as $definition) {
            foreach ($definition->relations as $relation) {
                if (! isset($relations[$relation->id])) {
                    $relations[$relation->id] = $relation;
                }
            }
        }

        return $relations;
    }
}
