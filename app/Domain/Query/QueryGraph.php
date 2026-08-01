<?php

namespace App\Domain\Query;

use App\Domain\Resource\RelationDefinition;
use App\Services\ResourceDefinitionRegistry;
use InvalidArgumentException;
use RuntimeException;

/**
 * Arbre de QueryNodes.
 *
 * Le QueryGraph représente CE QUE L'UTILISATEUR DEMANDE.
 * Il consulte le ResourceCatalog pour construire les QueryNodes,
 * mais ne contient pas la logique d'exécution ni les données Oracle.
 *
 * Exemple :
 *   $graph = new QueryGraph($rootNode, $policy);
 *   $graph->addChild($rootNode, $childNode, $relation);
 *   $graph->addChild($childNode, $grandchildNode, $relation);
 */
final class QueryGraph
{
    /** @var list<QueryEdge> Toutes les edges du graphe (plat, pour traversal rapide) */
    private array $edges = [];

    private readonly QueryGraphValidator $validator;

    public function __construct(
        public readonly QueryNode $root,
        public readonly QueryTraversalPolicy $policy = new QueryTraversalPolicy,
        ?QueryGraphValidator $validator = null,
    ) {
        $this->validator = $validator ?? new QueryGraphValidator;
        $this->edges = $this->validator->validate($this->root, $this->policy);
    }

    /**
     * Ajoute un enfant à un nœud parent.
     * Vérifie la policy avant d'ajouter.
     *
     * @throws RuntimeException si la profondeur dépasse le maximum
     */
    public function addChild(
        QueryNode $parent,
        QueryNode $child,
        RelationDefinition $relation,
    ): QueryEdge {
        $this->validator->assertCanAttach(
            root: $this->root,
            parent: $parent,
            child: $child,
            relation: $relation,
            policy: $this->policy,
        );

        $edge = $parent->addChild($child, $relation);
        $this->edges = $this->validator->validate($this->root, $this->policy);

        return $edge;
    }

    /**
     * Retourne tous les nœuds du graphe (racine + descendants) en ordre DFS.
     *
     * @return list<QueryNode>
     */
    public function allNodes(): array
    {
        $this->edges = $this->validator->validate($this->root, $this->policy);

        return [$this->root, ...$this->root->descendants()];
    }

    /** @return list<QueryEdge> */
    public function edges(): array
    {
        $this->edges = $this->validator->validate($this->root, $this->policy);

        return $this->edges;
    }

    /**
     * Retourne tous les nœuds feuilles (sans enfants).
     *
     * @return list<QueryNode>
     */
    public function leaves(): array
    {
        return array_values(array_filter($this->allNodes(), fn (QueryNode $n) => $n->isLeaf()));
    }

    /** Retourne la profondeur maximale actuelle du graphe. */
    public function currentDepth(): int
    {
        $max = 1;
        foreach ($this->allNodes() as $node) {
            if ($node->depth > $max) {
                $max = $node->depth;
            }
        }

        return $max;
    }

    /** Retourne true si le nœud ciblé peut recevoir un enfant. */
    public function canGrowDeeper(QueryNode $parent): bool
    {
        $nodes = $this->allNodes();

        if (! in_array($parent, $nodes, true)) {
            throw new InvalidArgumentException(
                "Cannot evaluate graph growth: parent '{$parent->nodeId}' does not belong to this graph.",
            );
        }

        return $this->policy->canAddChildFor($parent, count($nodes));
    }

    /**
     * Sérialise le graphe en tableau JSON-sérialisable.
     * Utilisé pour stocker dans queries.query_graph.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->edges = $this->validator->validate($this->root, $this->policy);

        return [
            'version' => 1,
            'maxDepth' => $this->policy->maxDepth,
            'maxNodes' => $this->policy->maxNodes,
            'root' => $this->root->toArray(),
        ];
    }

    /**
     * Construit un QueryGraph depuis un tableau (désérialisation depuis JSON).
     * Nécessite un ResourceCatalog pour résoudre les ResourceDefinitions.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ResourceDefinitionRegistry $registry): self
    {
        $version = $data['version'] ?? 1;

        if ($version !== 1) {
            throw new InvalidArgumentException('Unsupported QueryGraph version.');
        }

        $rootData = self::requiredArray($data, 'root', 'QueryGraph payload');
        $policy = new QueryTraversalPolicy(
            maxDepth: self::optionalPositiveInteger($data, 'maxDepth'),
            maxNodes: self::optionalPositiveInteger($data, 'maxNodes'),
        );
        $root = self::buildNodeFromArray($rootData, $registry, null, 1);
        $graph = new self($root, $policy);

        self::reconnectEdges($root, $rootData, $graph, $registry);

        return $graph;
    }

    /** @param  array<string, mixed>  $data */
    private static function buildNodeFromArray(
        array $data,
        ResourceDefinitionRegistry $registry,
        ?QueryNode $parent,
        int $depth,
    ): QueryNode {
        $resourceId = self::requiredString($data, 'resourceId', 'QueryGraph node');
        $resource = $registry->find($resourceId)
            ?? throw new RuntimeException("Unknown resource '{$resourceId}' during QueryGraph deserialization.");
        $nodeId = $data['nodeId'] ?? null;

        if ($nodeId !== null && ! is_string($nodeId)) {
            throw new InvalidArgumentException('QueryGraph nodeId must be a string.');
        }

        return new QueryNode(
            resource: $resource,
            fields: self::stringList($data, 'fields'),
            filters: self::associativeArray($data, 'filters'),
            parameters: self::associativeArray($data, 'parameters'),
            parent: $parent,
            depth: $depth,
            nodeId: $nodeId,
        );
    }

    /** @param array<string, mixed> $nodeData */
    private static function reconnectEdges(
        QueryNode $node,
        array $nodeData,
        QueryGraph $graph,
        ResourceDefinitionRegistry $registry,
    ): void {
        $children = $nodeData['children'] ?? [];

        if (! is_array($children) || ! array_is_list($children)) {
            throw new InvalidArgumentException('QueryGraph node children must be a list.');
        }

        foreach ($children as $childData) {
            if (! is_array($childData) || array_is_list($childData)) {
                throw new InvalidArgumentException('Each QueryGraph child entry must be an object.');
            }

            $relationId = self::relationId($childData);
            $relation = $registry->findRelation($relationId)
                ?? throw new RuntimeException("Unknown relation '{$relationId}' during deserialization.");
            $childNodeData = self::requiredArray($childData, 'node', 'QueryGraph child');

            $child = self::buildNodeFromArray(
                $childNodeData,
                $registry,
                $node,
                $node->depth + 1,
            );
            $graph->addChild($node, $child, $relation);
            self::reconnectEdges($child, $childNodeData, $graph, $registry);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function relationId(array $data): string
    {
        if (isset($data['relation'])) {
            return self::requiredString($data, 'relation', 'QueryGraph child');
        }

        return self::requiredString($data, 'relationId', 'QueryGraph child');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function requiredArray(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$context} field '{$key}' must be an object.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$context} field '{$key}' must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("QueryGraph node field '{$key}' must be a list.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("QueryGraph node field '{$key}' must contain non-empty strings.");
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function associativeArray(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value)) {
            throw new InvalidArgumentException("QueryGraph node field '{$key}' must be an object.");
        }

        foreach (array_keys($value) as $arrayKey) {
            if (! is_string($arrayKey)) {
                throw new InvalidArgumentException("QueryGraph node field '{$key}' must use string keys.");
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function optionalPositiveInteger(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("QueryGraph field '{$key}' must be a positive integer.");
        }

        return $value;
    }
}
