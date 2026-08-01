<?php

namespace App\Domain\Query;

use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\ResourceDefinition;

/**
 * Nœud dans le QueryGraph.
 *
 * Représente CE QUE L'UTILISATEUR DEMANDE pour une ResourceDefinition donnée.
 * Tous les niveaux de la requête hiérarchique sont des QueryNodes :
 *
 *   RootResource (depth=1)
 *   └── ChildResource (depth=2)
 *       └── GrandchildResource (depth=3)
 *
 * Le QueryNode ne contient pas la logique d'exécution —
 * il décrit uniquement la sélection (fields, filters, parameters).
 */
final class QueryNode
{
    /** Identifiant local unique dans le graphe. */
    public readonly string $nodeId;

    /**
     * @param  list<string>  $fields  Champs demandés (vide = tous)
     * @param  array<string, mixed>  $filters  Groupe de filtres structurés gouvernés
     * @param  array<string, mixed>  $parameters  limit, offset, orderBy, effectiveDate…
     * @param  list<QueryEdge>  $edges  Connexions vers les enfants
     */
    public function __construct(
        public readonly ResourceDefinition $resource,
        public array $fields = [],
        public array $filters = [],
        public array $parameters = [],
        /** Nœud parent dans le graphe — null pour la racine */
        public readonly ?QueryNode $parent = null,
        /** Profondeur dans le graphe (1 = racine) */
        public readonly int $depth = 1,
        ?string $nodeId = null,
        private array $edges = [],
    ) {
        $this->nodeId = $nodeId ?? QueryNodeIdFactory::generate();
    }

    /** @return list<QueryEdge> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return list<QueryNode> */
    public function children(): array
    {
        return array_map(fn (QueryEdge $e) => $e->child, $this->edges);
    }

    /** Retourne true si ce nœud est la racine du graphe. */
    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    /** Retourne true si ce nœud n'a aucun enfant. */
    public function isLeaf(): bool
    {
        return $this->edges === [];
    }

    /**
     * Ajoute un enfant à ce nœud via une RelationDefinition.
     * Retourne l'edge créé.
     */
    public function addChild(QueryNode $child, RelationDefinition $relation): QueryEdge
    {
        $edge = new QueryEdge($this, $child, $relation);
        $this->edges[] = $edge;

        return $edge;
    }

    /**
     * Retourne tous les ancêtres de ce nœud, du plus proche au plus éloigné.
     *
     * @return list<QueryNode>
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current !== null) {
            $ancestors[] = $current;
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Retourne tous les descendants de ce nœud (traversée DFS).
     *
     * @return list<QueryNode>
     */
    public function descendants(): array
    {
        $result = [];

        foreach ($this->children() as $child) {
            $result[] = $child;
            foreach ($child->descendants() as $descendant) {
                $result[] = $descendant;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'resourceId' => $this->resource->id,
            'depth' => $this->depth,
            'fields' => $this->fields,
            'filters' => $this->filters,
            'parameters' => $this->parameters,
            'children' => array_map(
                fn (QueryEdge $e) => [
                    'relation' => $e->relation->id,
                    'node' => $e->child->toArray(),
                ],
                $this->edges,
            ),
        ];
    }
}
