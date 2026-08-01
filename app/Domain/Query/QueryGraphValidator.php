<?php

namespace App\Domain\Query;

use App\Domain\Query\Filter\FilterGroup;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\ResourceDefinition;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Vérifie les invariants structurels et les sélections d'un Query Graph.
 *
 * Le parcours est protégé par l'identité objet et par nodeId afin de refuser
 * un cycle avant tout appel récursif à QueryNode::descendants() ou toArray().
 */
final class QueryGraphValidator
{
    private const int MAX_LIMIT = 500;

    private const int MAX_STRING_PARAMETER_LENGTH = 500;

    /**
     * Valide un arbre complet et retourne ses arêtes dans l'ordre DFS.
     *
     * @return list<QueryEdge>
     */
    public function validate(QueryNode $root, QueryTraversalPolicy $policy): array
    {
        $state = $this->emptyState();
        $this->visit(
            node: $root,
            expectedParent: null,
            expectedDepth: 1,
            policy: $policy,
            state: $state,
        );

        return $state['edges'];
    }

    /**
     * Valide une future attache sans modifier le graphe.
     *
     * L'arête parent → enfant est créée ensuite par QueryGraph.
     */
    public function assertCanAttach(
        QueryNode $root,
        QueryNode $parent,
        QueryNode $child,
        RelationDefinition $relation,
        QueryTraversalPolicy $policy,
    ): void {
        $state = $this->inspect($root, $policy);

        if (! in_array($parent, $state['nodes'], true)) {
            throw new InvalidArgumentException(
                "Cannot attach query node '{$child->nodeId}': parent does not belong to this graph.",
            );
        }

        if (in_array($child, $state['nodes'], true)) {
            if ($child === $parent || in_array($child, $parent->ancestors(), true)) {
                throw new InvalidArgumentException(
                    "Cannot attach query node '{$child->nodeId}': this edge would create a structural cycle.",
                );
            }

            throw new InvalidArgumentException(
                "Cannot attach query node '{$child->nodeId}': the node already belongs to this graph.",
            );
        }

        $policy->assertCanAddChildFor($parent, count($state['nodes']));
        $this->assertRelationMatches($parent, $child, $relation);

        foreach ($parent->edges() as $edge) {
            if ($edge->relation->id === $relation->id) {
                throw new InvalidArgumentException(
                    "Relation '{$relation->id}' is already used below parent '{$parent->nodeId}'.",
                );
            }
        }

        $this->visit(
            node: $child,
            expectedParent: $parent,
            expectedDepth: $parent->depth + 1,
            policy: $policy,
            state: $state,
        );
    }

    /**
     * @return array{
     *     nodes: list<QueryNode>,
     *     edges: list<QueryEdge>,
     *     nodeIds: array<string, true>,
     *     activeNodeIds: array<string, true>,
     *     visitedObjects: array<int, true>,
     *     activeObjects: array<int, true>
     * }
     */
    private function inspect(QueryNode $root, QueryTraversalPolicy $policy): array
    {
        $state = $this->emptyState();
        $this->visit(
            node: $root,
            expectedParent: null,
            expectedDepth: 1,
            policy: $policy,
            state: $state,
        );

        return $state;
    }

    /**
     * @return array{
     *     nodes: list<QueryNode>,
     *     edges: list<QueryEdge>,
     *     nodeIds: array<string, true>,
     *     activeNodeIds: array<string, true>,
     *     visitedObjects: array<int, true>,
     *     activeObjects: array<int, true>
     * }
     */
    private function emptyState(): array
    {
        return [
            'nodes' => [],
            'edges' => [],
            'nodeIds' => [],
            'activeNodeIds' => [],
            'visitedObjects' => [],
            'activeObjects' => [],
        ];
    }

    /**
     * @param  array{
     *     nodes: list<QueryNode>,
     *     edges: list<QueryEdge>,
     *     nodeIds: array<string, true>,
     *     activeNodeIds: array<string, true>,
     *     visitedObjects: array<int, true>,
     *     activeObjects: array<int, true>
     * }  $state
     */
    private function visit(
        QueryNode $node,
        ?QueryNode $expectedParent,
        int $expectedDepth,
        QueryTraversalPolicy $policy,
        array &$state,
    ): void {
        $objectId = spl_object_id($node);

        if (isset($state['activeObjects'][$objectId]) || isset($state['activeNodeIds'][$node->nodeId])) {
            throw new InvalidArgumentException(
                "Query graph contains a structural cycle at node '{$node->nodeId}'.",
            );
        }

        if (isset($state['visitedObjects'][$objectId])) {
            throw new InvalidArgumentException(
                "Query node '{$node->nodeId}' is referenced more than once; the query graph must be a tree.",
            );
        }

        QueryNodeIdFactory::assertValid($node->nodeId);

        if (isset($state['nodeIds'][$node->nodeId])) {
            throw new InvalidArgumentException("Duplicate nodeId '{$node->nodeId}' in query graph.");
        }

        if ($node->parent !== $expectedParent) {
            throw new InvalidArgumentException(
                "Query node '{$node->nodeId}' has an inconsistent parent reference.",
            );
        }

        if ($node->depth !== $expectedDepth) {
            throw new InvalidArgumentException(
                "Query node '{$node->nodeId}' has depth {$node->depth}; expected depth {$expectedDepth}.",
            );
        }

        $policy->assertDepthAllowed($expectedDepth);
        $this->validateSelection($node);

        $state['nodes'][] = $node;
        $state['nodeIds'][$node->nodeId] = true;
        $state['activeNodeIds'][$node->nodeId] = true;
        $state['activeObjects'][$objectId] = true;
        $policy->assertNodeCountAllowed(count($state['nodes']));

        $relationIds = [];

        foreach ($node->edges() as $edge) {
            if ($edge->parent !== $node) {
                throw new InvalidArgumentException(
                    "Relation '{$edge->relation->id}' is attached to an inconsistent parent instance.",
                );
            }

            if (isset($relationIds[$edge->relation->id])) {
                throw new InvalidArgumentException(
                    "Relation '{$edge->relation->id}' is already used below parent '{$node->nodeId}'.",
                );
            }

            $relationIds[$edge->relation->id] = true;
            $this->assertRelationMatches($node, $edge->child, $edge->relation);
            $state['edges'][] = $edge;

            $this->visit(
                node: $edge->child,
                expectedParent: $node,
                expectedDepth: $expectedDepth + 1,
                policy: $policy,
                state: $state,
            );
        }

        unset($state['activeNodeIds'][$node->nodeId], $state['activeObjects'][$objectId]);
        $state['visitedObjects'][$objectId] = true;
    }

    private function assertRelationMatches(
        QueryNode $parent,
        QueryNode $child,
        RelationDefinition $relation,
    ): void {
        if ($relation->sourceId !== $parent->resource->id) {
            throw new InvalidArgumentException(
                "Relation '{$relation->id}' source '{$relation->sourceId}' does not match parent resource '{$parent->resource->id}'.",
            );
        }

        if ($relation->targetId !== $child->resource->id) {
            throw new InvalidArgumentException(
                "Relation '{$relation->id}' target '{$relation->targetId}' does not match child resource '{$child->resource->id}'.",
            );
        }

        $declaredRelation = null;

        foreach ($parent->resource->relations as $candidate) {
            if ($candidate->id === $relation->id) {
                $declaredRelation = $candidate;

                break;
            }
        }

        if ($declaredRelation === null) {
            throw new InvalidArgumentException(
                "Relation '{$relation->id}' is not declared by parent resource '{$parent->resource->id}'.",
            );
        }

        if ($declaredRelation->toArray() !== $relation->toArray()) {
            throw new InvalidArgumentException(
                "Relation '{$relation->id}' does not match the definition declared by parent resource '{$parent->resource->id}'.",
            );
        }
    }

    private function validateSelection(QueryNode $node): void
    {
        $resource = $node->resource;

        if ($node->fields !== [] && ! $resource->capabilities->supportsFields) {
            throw new InvalidArgumentException(
                "Resource '{$resource->id}' does not support field projection.",
            );
        }

        $selectedFields = [];

        foreach ($node->fields as $fieldName) {
            if ($resource->field($fieldName) === null) {
                throw new InvalidArgumentException(
                    "Field '{$fieldName}' is not exposed by resource '{$resource->id}'.",
                );
            }

            if (isset($selectedFields[$fieldName])) {
                throw new InvalidArgumentException(
                    "Field '{$fieldName}' is selected more than once on resource '{$resource->id}'.",
                );
            }

            $selectedFields[$fieldName] = true;
        }

        if ($node->filters !== []) {
            if (! $resource->capabilities->supportsQ) {
                throw new InvalidArgumentException(
                    "Resource '{$resource->id}' does not support filters.",
                );
            }

            FilterGroup::fromArray($node->filters, $resource);
        }

        foreach ($node->parameters as $parameter => $value) {
            $this->validateParameter($resource, $parameter, $value);
        }
    }

    private function validateParameter(
        ResourceDefinition $resource,
        string $parameter,
        mixed $value,
    ): void {
        $capabilities = $resource->capabilities;

        match ($parameter) {
            'expand' => throw new InvalidArgumentException(
                "Parameter 'expand' is not allowed in a Query Graph; model child resources as graph edges.",
            ),
            'orderBy' => $this->validateOrderBy($resource, $value),
            'limit' => $this->requireSupportedInteger(
                $resource,
                $parameter,
                $value,
                $capabilities->supportsLimit,
                minimum: 1,
                maximum: self::MAX_LIMIT,
            ),
            'offset' => $this->requireSupportedInteger(
                $resource,
                $parameter,
                $value,
                $capabilities->supportsOffset,
                minimum: 0,
            ),
            'effectiveDate' => $this->validateEffectiveDate($resource, $value),
            'finder' => $this->validateFinder($resource, $value),
            default => throw new InvalidArgumentException(
                "Parameter '{$parameter}' is not allowed for resource '{$resource->id}'.",
            ),
        };
    }

    private function validateOrderBy(ResourceDefinition $resource, mixed $value): void
    {
        $value = $this->requireSupportedString(
            $resource,
            'orderBy',
            $value,
            $resource->capabilities->supportsOrderBy,
            maximumLength: self::MAX_STRING_PARAMETER_LENGTH,
        );

        foreach (explode(',', $value) as $clause) {
            [$fieldName, $direction] = array_pad(explode(':', trim($clause), 2), 2, 'asc');
            $field = $resource->field(trim($fieldName));

            if ($field === null || ! $field->sortable) {
                throw new InvalidArgumentException(
                    "Field '{$fieldName}' cannot be used in orderBy for resource '{$resource->id}'.",
                );
            }

            if (! in_array(strtolower(trim($direction)), ['asc', 'desc'], true)) {
                throw new InvalidArgumentException(
                    "Invalid orderBy direction '{$direction}' for resource '{$resource->id}'.",
                );
            }
        }
    }

    private function validateFinder(ResourceDefinition $resource, mixed $value): void
    {
        $value = $this->requireSupportedString(
            $resource,
            'finder',
            $value,
            $resource->capabilities->supportsFinder,
            maximumLength: self::MAX_STRING_PARAMETER_LENGTH,
        );

        if (str_contains($value, ';')) {
            throw new InvalidArgumentException(
                "Finder arguments are not allowed without a governed schema for resource '{$resource->id}'.",
            );
        }

        $allowedFinders = $resource->capabilities->finders;

        if (! in_array($value, $allowedFinders, true)) {
            throw new InvalidArgumentException(
                "Finder '{$value}' is not allowed for resource '{$resource->id}'.",
            );
        }
    }

    private function validateEffectiveDate(ResourceDefinition $resource, mixed $value): void
    {
        $value = $this->requireSupportedString(
            $resource,
            'effectiveDate',
            $value,
            $resource->capabilities->supportsEffectiveDate,
        );
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(
                "Parameter 'effectiveDate' must use the YYYY-MM-DD format for resource '{$resource->id}'.",
            );
        }
    }

    private function requireSupportedString(
        ResourceDefinition $resource,
        string $parameter,
        mixed $value,
        bool $supported,
        ?int $maximumLength = null,
    ): string {
        if (! $supported) {
            throw new InvalidArgumentException(
                "Parameter '{$parameter}' is not supported by resource '{$resource->id}'.",
            );
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Parameter '{$parameter}' must be a non-empty string for resource '{$resource->id}'.",
            );
        }

        if ($maximumLength !== null && strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Parameter '{$parameter}' must not exceed {$maximumLength} characters for resource '{$resource->id}'.",
            );
        }

        return $value;
    }

    private function requireSupportedInteger(
        ResourceDefinition $resource,
        string $parameter,
        mixed $value,
        bool $supported,
        int $minimum,
        ?int $maximum = null,
    ): void {
        if (! $supported) {
            throw new InvalidArgumentException(
                "Parameter '{$parameter}' is not supported by resource '{$resource->id}'.",
            );
        }

        if (! is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            $range = $maximum === null
                ? "greater than or equal to {$minimum}"
                : "between {$minimum} and {$maximum}";

            throw new InvalidArgumentException(
                "Parameter '{$parameter}' must be an integer {$range} for resource '{$resource->id}'.",
            );
        }
    }
}
