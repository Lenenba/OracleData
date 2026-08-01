<?php

namespace App\Domain\Query;

use InvalidArgumentException;

/**
 * Policy de traversée du QueryGraph.
 *
 * Le moteur est récursif et techniquement illimité.
 * maxDepth est une règle métier — pas une contrainte technique.
 * Profondeur 1 = la ressource racine elle-même.
 */
final class QueryTraversalPolicy
{
    public const DEFAULT_MAX_DEPTH = 6;

    public const DEFAULT_MAX_NODES = 50;

    public readonly int $maxDepth;

    public readonly int $maxNodes;

    public function __construct(
        ?int $maxDepth = null,
        ?int $maxNodes = null,
    ) {
        $serverMaxDepth = self::configuredLimit(
            'fusion.query_graph.max_depth',
            self::DEFAULT_MAX_DEPTH,
        );
        $serverMaxNodes = self::configuredLimit(
            'fusion.query_graph.max_nodes',
            self::DEFAULT_MAX_NODES,
        );

        self::assertPositive('configured maxDepth', $serverMaxDepth);
        self::assertPositive('configured maxNodes', $serverMaxNodes);

        if ($maxDepth !== null) {
            self::assertPositive('maxDepth', $maxDepth);
        }

        if ($maxNodes !== null) {
            self::assertPositive('maxNodes', $maxNodes);
        }

        $this->maxDepth = min($maxDepth ?? $serverMaxDepth, $serverMaxDepth);
        $this->maxNodes = min($maxNodes ?? $serverMaxNodes, $serverMaxNodes);
    }

    /**
     * Vérifie si le parent ciblé peut recevoir un enfant.
     *
     * Le test porte sur la profondeur de cette branche, jamais sur la profondeur
     * maximale globale du graphe. Le nombre courant inclut la racine.
     */
    public function canAddChildFor(QueryNode|int $parent, int $currentNodeCount): bool
    {
        $parentDepth = $this->parentDepth($parent);
        $this->assertValidNodeCount($currentNodeCount);

        return $parentDepth < $this->maxDepth
            && $currentNodeCount < $this->maxNodes;
    }

    /** Lève une exception si le parent ciblé ne peut pas recevoir un enfant. */
    public function assertCanAddChildFor(QueryNode|int $parent, int $currentNodeCount): void
    {
        $parentDepth = $this->parentDepth($parent);
        $this->assertValidNodeCount($currentNodeCount);

        if ($parentDepth >= $this->maxDepth) {
            throw new \RuntimeException(
                "Cannot add a child below depth {$parentDepth}; the maximum allowed depth is {$this->maxDepth}.",
            );
        }

        if ($currentNodeCount >= $this->maxNodes) {
            throw new \RuntimeException(
                "Cannot add a child to a graph with {$currentNodeCount} nodes; the maximum allowed node count is {$this->maxNodes}.",
            );
        }
    }

    /** Lève une exception si la profondeur cible dépasse la limite. */
    public function assertDepthAllowed(int $depth): void
    {
        if ($depth < 1) {
            throw new InvalidArgumentException(
                "QueryTraversalPolicy: depth must be >= 1, got {$depth}.",
            );
        }

        if ($depth > $this->maxDepth) {
            throw new \RuntimeException(
                "Query depth {$depth} exceeds the maximum allowed depth of {$this->maxDepth}.",
            );
        }
    }

    /** Lève une exception si un graphe dépasse le budget de nœuds serveur. */
    public function assertNodeCountAllowed(int $nodeCount): void
    {
        $this->assertValidNodeCount($nodeCount);

        if ($nodeCount > $this->maxNodes) {
            throw new \RuntimeException(
                "Query node count {$nodeCount} exceeds the maximum allowed node count of {$this->maxNodes}.",
            );
        }
    }

    private function parentDepth(QueryNode|int $parent): int
    {
        $depth = $parent instanceof QueryNode ? $parent->depth : $parent;

        if ($depth < 1) {
            throw new InvalidArgumentException(
                "QueryTraversalPolicy: parent depth must be >= 1, got {$depth}.",
            );
        }

        return $depth;
    }

    private function assertValidNodeCount(int $nodeCount): void
    {
        if ($nodeCount < 1) {
            throw new InvalidArgumentException(
                "QueryTraversalPolicy: current node count must be >= 1, got {$nodeCount}.",
            );
        }
    }

    private static function configuredLimit(string $key, int $default): int
    {
        if (! app()->bound('config')) {
            return $default;
        }

        return (int) config($key, $default);
    }

    private static function assertPositive(string $name, int $value): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(
                "QueryTraversalPolicy: {$name} must be >= 1, got {$value}.",
            );
        }
    }
}
