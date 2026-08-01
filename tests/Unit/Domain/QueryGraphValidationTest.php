<?php

use App\Domain\Query\QueryGraph;
use App\Domain\Query\QueryNode;
use App\Domain\Query\QueryNodeIdFactory;
use App\Domain\Query\QueryTraversalPolicy;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\RelationType;
use App\Domain\Resource\ResourceDefinition;
use App\Services\ResourceDefinitionRegistry;
use App\Services\Workers\WorkersResourceRegistry;

function validationNodeId(int $suffix): string
{
    return sprintf('550e8400-e29b-41d4-a716-%012x', $suffix);
}

function validationRelation(
    string $id,
    string $sourceId,
    string $targetId,
): RelationDefinition {
    return new RelationDefinition(
        id: $id,
        sourceId: $sourceId,
        targetId: $targetId,
        type: RelationType::CHILD,
        pathTemplate: "/{$sourceId}/child/{$targetId}",
    );
}

/**
 * @param  list<RelationDefinition>  $relations
 * @param  list<FieldDefinition>|null  $fields
 */
function validationResource(
    string $id,
    array $relations = [],
    ?QueryCapabilities $capabilities = null,
    ?array $fields = null,
): ResourceDefinition {
    return new ResourceDefinition(
        id: $id,
        name: $id,
        module: 'validation',
        label: $id,
        collectionPath: "/{$id}",
        itemPath: "/{$id}/{Id}",
        apiVersion: '1',
        capabilities: $capabilities ?? QueryCapabilities::standard(),
        fields: $fields ?? [
            FieldDefinition::identifier('Id'),
            FieldDefinition::string('Name'),
            new FieldDefinition('Locked', sortable: false),
        ],
        relations: $relations,
    );
}

/**
 * @return array{
 *     graph: QueryGraph,
 *     root: QueryNode,
 *     childResource: ResourceDefinition,
 *     relation: RelationDefinition
 * }
 */
function validSingleEdgeGraph(): array
{
    $relation = validationRelation('root.to.child', 'validation.root', 'validation.child');
    $rootResource = validationResource('validation.root', [$relation]);
    $childResource = validationResource('validation.child');
    $root = new QueryNode(
        $rootResource,
        fields: ['Id'],
        nodeId: validationNodeId(1),
    );

    return [
        'graph' => new QueryGraph($root),
        'root' => $root,
        'childResource' => $childResource,
        'relation' => $relation,
    ];
}

test('QueryNode generates a unique valid ULID when the server does not receive an id', function () {
    $resource = validationResource('validation.generated');

    $first = new QueryNode($resource);
    $second = new QueryNode($resource);

    expect(QueryNodeIdFactory::isValid($first->nodeId))->toBeTrue()
        ->and(QueryNodeIdFactory::isValid($second->nodeId))->toBeTrue()
        ->and($second->nodeId)->not->toBe($first->nodeId);
});

test('QueryGraph rejects a root node with a non UUID or ULID id', function () {
    $root = new QueryNode(validationResource('validation.root'), nodeId: 'client-controlled-id');

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'UUID or ULID');
});

test('QueryGraph rejects a detached parent when adding a child', function () {
    ['graph' => $graph, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $detachedParent = new QueryNode(
        validationResource('validation.root', [$relation]),
        nodeId: validationNodeId(2),
    );
    $child = new QueryNode(
        $childResource,
        parent: $detachedParent,
        depth: 2,
        nodeId: validationNodeId(3),
    );

    expect(fn () => $graph->addChild($detachedParent, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'parent does not belong');
});

test('QueryGraph rejects a relation whose source is not the parent resource', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource] = validSingleEdgeGraph();
    $relation = validationRelation('wrong.source', 'validation.other', 'validation.child');
    $child = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );

    expect(fn () => $graph->addChild($root, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'source');
});

test('QueryGraph rejects a relation whose target is not the child resource', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource] = validSingleEdgeGraph();
    $relation = validationRelation('wrong.target', 'validation.root', 'validation.other');
    $child = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );

    expect(fn () => $graph->addChild($root, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'target');
});

test('QueryGraph rejects a relation that is not declared by the parent resource', function () {
    $relation = validationRelation('root.to.child', 'validation.root', 'validation.child');
    $root = new QueryNode(
        validationResource('validation.root'),
        nodeId: validationNodeId(1),
    );
    $graph = new QueryGraph($root);
    $child = new QueryNode(
        validationResource('validation.child'),
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );

    expect(fn () => $graph->addChild($root, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'not declared');
});

test('QueryGraph rejects a forged relation that differs from the declared definition', function () {
    $declared = validationRelation('root.to.child', 'validation.root', 'validation.child');
    $forged = new RelationDefinition(
        id: $declared->id,
        sourceId: $declared->sourceId,
        targetId: $declared->targetId,
        type: RelationType::CHILD,
        pathTemplate: '/forged/path',
    );
    $root = new QueryNode(
        validationResource('validation.root', [$declared]),
        nodeId: validationNodeId(1),
    );
    $graph = new QueryGraph($root);
    $child = new QueryNode(
        validationResource('validation.child'),
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );

    expect(fn () => $graph->addChild($root, $child, $forged))
        ->toThrow(InvalidArgumentException::class, 'does not match');
});

test('QueryGraph deserialization rejects a registry relation not declared by the payload parent resource', function () {
    $relation = validationRelation('root.to.child', 'validation.root', 'validation.child');
    $rootResourceWithRelation = validationResource('validation.root', [$relation]);
    $rootResource = validationResource('validation.root');
    $childResource = validationResource('validation.child');
    $registry = new ResourceDefinitionRegistry(new WorkersResourceRegistry);

    // Simulate a stale global relation entry after the resource definition was refreshed.
    foreach ([$rootResourceWithRelation, $rootResource, $childResource] as $resource) {
        $registry->register($resource);
    }

    $payload = [
        'version' => 1,
        'root' => [
            'nodeId' => validationNodeId(1),
            'resourceId' => $rootResource->id,
            'fields' => [],
            'filters' => [],
            'parameters' => [],
            'children' => [[
                'relation' => $relation->id,
                'node' => [
                    'nodeId' => validationNodeId(2),
                    'resourceId' => $childResource->id,
                    'fields' => [],
                    'filters' => [],
                    'parameters' => [],
                    'children' => [],
                ],
            ]],
        ],
    ];

    expect(fn () => QueryGraph::fromArray($payload, $registry))
        ->toThrow(InvalidArgumentException::class, 'not declared');
});

test('QueryGraph rejects a child whose parent reference is inconsistent', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $child = new QueryNode(
        $childResource,
        depth: 2,
        nodeId: validationNodeId(2),
    );

    expect(fn () => $graph->addChild($root, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'parent');
});

test('QueryGraph rejects a child whose depth is not parent depth plus one', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $child = new QueryNode(
        $childResource,
        parent: $root,
        depth: 3,
        nodeId: validationNodeId(2),
    );

    expect(fn () => $graph->addChild($root, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'depth');
});

test('QueryGraph rejects duplicate node ids', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $child = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: $root->nodeId,
    );

    expect(fn () => $graph->addChild($root, $child, $relation))
        ->toThrow(InvalidArgumentException::class, 'Duplicate nodeId');
});

test('QueryGraph rejects a structural cycle back to an ancestor instance', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $child = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );
    $graph->addChild($root, $child, $relation);
    $backToRoot = validationRelation('child.to.root', 'validation.child', 'validation.root');

    expect(fn () => $graph->addChild($child, $root, $backToRoot))
        ->toThrow(InvalidArgumentException::class, 'cycle');
});

test('QueryGraph rejects the same relation twice below one parent', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $first = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );
    $second = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(3),
    );

    $graph->addChild($root, $first, $relation);

    expect(fn () => $graph->addChild($root, $second, $relation))
        ->toThrow(InvalidArgumentException::class, 'already used');
});

test('QueryGraph rejects a node beyond the configured maxNodes budget', function () {
    $firstRelation = validationRelation('root.to.first', 'validation.root', 'validation.first');
    $secondRelation = validationRelation('root.to.second', 'validation.root', 'validation.second');
    $root = new QueryNode(
        validationResource('validation.root', [$firstRelation, $secondRelation]),
        nodeId: validationNodeId(1),
    );
    $graph = new QueryGraph($root, new QueryTraversalPolicy(maxDepth: 6, maxNodes: 2));
    $first = new QueryNode(
        validationResource('validation.first'),
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );
    $second = new QueryNode(
        validationResource('validation.second'),
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(3),
    );

    $graph->addChild($root, $first, $firstRelation);

    expect($graph->allNodes())->toHaveCount(2);

    expect(fn () => $graph->addChild($root, $second, $secondRelation))
        ->toThrow(RuntimeException::class, 'maximum');
});

test('QueryGraph permits the same resource in distinct node instances', function () {
    $selfRelation = validationRelation('resource.to.self', 'validation.repeated', 'validation.repeated');
    $resource = validationResource('validation.repeated', [$selfRelation]);
    $root = new QueryNode($resource, nodeId: validationNodeId(1));
    $graph = new QueryGraph($root);
    $child = new QueryNode(
        $resource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );

    $graph->addChild($root, $child, $selfRelation);

    expect($graph->allNodes())->toHaveCount(2);
});

test('QueryGraph rejects fields that the resource does not expose', function () {
    $root = new QueryNode(
        validationResource('validation.root'),
        fields: ['UnknownField'],
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'UnknownField');
});

test('QueryGraph rejects field projection when the resource does not support fields', function () {
    $capabilities = new QueryCapabilities(supportsFields: false);
    $root = new QueryNode(
        validationResource('validation.root', capabilities: $capabilities),
        fields: ['Id'],
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'field projection');
});

test('QueryGraph rejects filters when the resource does not support them', function () {
    $capabilities = new QueryCapabilities(supportsQ: false);
    $root = new QueryNode(
        validationResource('validation.root', capabilities: $capabilities),
        filters: [
            'logic' => 'and',
            'conditions' => [[
                'field' => 'Name',
                'operator' => 'eq',
                'value' => 'Alice',
            ]],
        ],
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'filters');
});

test('QueryGraph rejects parameters that the resource does not support', function () {
    $capabilities = new QueryCapabilities(supportsLimit: false);
    $root = new QueryNode(
        validationResource('validation.root', capabilities: $capabilities),
        parameters: ['limit' => 25],
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'limit');
});

test('QueryGraph rejects unknown free-form parameters', function () {
    $root = new QueryNode(
        validationResource('validation.root'),
        parameters: ['q' => 'Name=Alice'],
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'q');
});

test('QueryGraph rejects incompatible parameter values and ungoverned sort fields', function (
    array $parameters,
    QueryCapabilities $capabilities,
    string $message,
) {
    $root = new QueryNode(
        validationResource('validation.root', capabilities: $capabilities),
        parameters: $parameters,
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'limit must be an integer' => [
        ['limit' => '25'],
        QueryCapabilities::standard(),
        'integer',
    ],
    'limit cannot exceed the application ceiling' => [
        ['limit' => 501],
        QueryCapabilities::standard(),
        'between 1 and 500',
    ],
    'offset cannot be negative' => [
        ['offset' => -1],
        QueryCapabilities::standard(),
        'greater than or equal',
    ],
    'orderBy field must be sortable' => [
        ['orderBy' => 'Locked:asc'],
        QueryCapabilities::standard(),
        'cannot be used',
    ],
    'orderBy direction is governed' => [
        ['orderBy' => 'Name:sideways'],
        QueryCapabilities::standard(),
        'direction',
    ],
    'effectiveDate uses an ISO date' => [
        ['effectiveDate' => '30/07/2026'],
        new QueryCapabilities(supportsEffectiveDate: true),
        'YYYY-MM-DD',
    ],
    'finder must be declared' => [
        ['finder' => 'byName'],
        new QueryCapabilities(supportsFinder: true, finders: []),
        'not allowed',
    ],
    'finder arguments require a governed schema' => [
        ['finder' => 'byName;Name=Alice'],
        new QueryCapabilities(supportsFinder: true, finders: ['byName']),
        'governed schema',
    ],
]);

test('QueryGraph rejects expand because child selection belongs to graph edges', function () {
    $root = new QueryNode(
        validationResource(
            'validation.root',
            capabilities: new QueryCapabilities(supportsExpand: true),
        ),
        parameters: ['expand' => 'undeclared.children'],
        nodeId: validationNodeId(1),
    );

    expect(fn () => new QueryGraph($root))
        ->toThrow(InvalidArgumentException::class, 'graph edges');
});

test('QueryGraph accepts governed parameter values', function () {
    $capabilities = new QueryCapabilities(
        supportsEffectiveDate: true,
        supportsFinder: true,
        finders: ['byName'],
    );
    $root = new QueryNode(
        validationResource('validation.root', capabilities: $capabilities),
        parameters: [
            'orderBy' => 'Name:desc',
            'limit' => 500,
            'offset' => 0,
            'effectiveDate' => '2026-07-30',
            'finder' => 'byName',
        ],
        nodeId: validationNodeId(1),
    );

    expect(new QueryGraph($root))->toBeInstanceOf(QueryGraph::class);
});

test('QueryGraph calculates depth from the tree and ignores persisted depth values', function () {
    ['graph' => $graph, 'root' => $root, 'childResource' => $childResource, 'relation' => $relation] = validSingleEdgeGraph();
    $child = new QueryNode(
        $childResource,
        parent: $root,
        depth: 2,
        nodeId: validationNodeId(2),
    );
    $graph->addChild($root, $child, $relation);
    $payload = $graph->toArray();
    $payload['root']['depth'] = 99;
    $payload['root']['children'][0]['node']['depth'] = 99;
    $payload['maxDepth'] = 999;
    $payload['maxNodes'] = 999;

    $registry = new ResourceDefinitionRegistry(new WorkersResourceRegistry);
    $registry->register($root->resource);
    $registry->register($childResource);

    $restored = QueryGraph::fromArray($payload, $registry);

    expect($restored->root->depth)->toBe(1)
        ->and($restored->root->children()[0]->depth)->toBe(2)
        ->and($restored->policy->maxDepth)->toBe(QueryTraversalPolicy::DEFAULT_MAX_DEPTH)
        ->and($restored->policy->maxNodes)->toBe(QueryTraversalPolicy::DEFAULT_MAX_NODES);
});

test('QueryGraph deserialization generates a server node id when it is absent', function () {
    $resource = validationResource('validation.root');
    $registry = new ResourceDefinitionRegistry(new WorkersResourceRegistry);
    $registry->register($resource);

    $graph = QueryGraph::fromArray([
        'version' => 1,
        'root' => [
            'resourceId' => $resource->id,
            'fields' => [],
            'filters' => [],
            'parameters' => [],
            'children' => [],
        ],
    ], $registry);

    expect(QueryNodeIdFactory::isValid($graph->root->nodeId))->toBeTrue();
});

test('QueryGraph deserialization requires children to be a list', function () {
    $resource = validationResource('validation.root');
    $registry = new ResourceDefinitionRegistry(new WorkersResourceRegistry);
    $registry->register($resource);

    $payload = [
        'version' => 1,
        'root' => [
            'nodeId' => validationNodeId(1),
            'resourceId' => $resource->id,
            'fields' => [],
            'filters' => [],
            'parameters' => [],
            'children' => ['forged' => []],
        ],
    ];

    expect(fn () => QueryGraph::fromArray($payload, $registry))
        ->toThrow(InvalidArgumentException::class, 'must be a list');
});

test('QueryGraph deserialization requires each child entry to be object-shaped', function () {
    $resource = validationResource('validation.root');
    $registry = new ResourceDefinitionRegistry(new WorkersResourceRegistry);
    $registry->register($resource);

    $payload = [
        'version' => 1,
        'root' => [
            'nodeId' => validationNodeId(1),
            'resourceId' => $resource->id,
            'fields' => [],
            'filters' => [],
            'parameters' => [],
            'children' => [[]],
        ],
    ];

    expect(fn () => QueryGraph::fromArray($payload, $registry))
        ->toThrow(InvalidArgumentException::class, 'must be an object');
});
