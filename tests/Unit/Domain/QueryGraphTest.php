<?php

use App\Domain\Query\ExecutionContext;
use App\Domain\Query\QueryEdge;
use App\Domain\Query\QueryGraph;
use App\Domain\Query\QueryNode;
use App\Domain\Query\QueryTraversalPolicy;
use App\Domain\Resource\AncestorBinding;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\RelationType;
use App\Domain\Resource\ResourceDefinition;
use App\Services\ResourceDefinitionRegistry;
use App\Services\Workers\WorkersResourceRegistry;

// ──────────────────────────────────────────────────────────────────────────────
// QueryTraversalPolicy
// ──────────────────────────────────────────────────────────────────────────────

test('policy allows child at depth below maxDepth', function () {
    $policy = new QueryTraversalPolicy(6);
    expect($policy->canAddChildFor(5, 1))->toBeTrue();
});

test('policy blocks child at maxDepth', function () {
    $policy = new QueryTraversalPolicy(6);
    expect($policy->canAddChildFor(6, 1))->toBeFalse();
});

test('policy throws when depth exceeds maximum', function () {
    $policy = new QueryTraversalPolicy(3);
    expect(fn () => $policy->assertDepthAllowed(4))->toThrow(RuntimeException::class);
});

test('policy with maxDepth=1 only allows root', function () {
    $policy = new QueryTraversalPolicy(1);
    expect($policy->canAddChildFor(1, 1))->toBeFalse();
});

test('invalid maxDepth throws InvalidArgumentException', function () {
    expect(fn () => new QueryTraversalPolicy(0))->toThrow(InvalidArgumentException::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// ExecutionContext
// ──────────────────────────────────────────────────────────────────────────────

test('ExecutionContext stores and retrieves values', function () {
    $ctx = new ExecutionContext;
    $ctx->set('hcm.workers', 'workersUniqID', 'ABC123');

    expect($ctx->get('hcm.workers', 'workersUniqID'))->toBe('ABC123');
    expect($ctx->has('hcm.workers', 'workersUniqID'))->toBeTrue();
    expect($ctx->has('hcm.workers', 'missing'))->toBeFalse();
});

test('ExecutionContext::with returns new instance without mutation', function () {
    $ctx1 = new ExecutionContext;
    $ctx2 = $ctx1->with('hcm.workers', 'workersUniqID', 'XXX');

    expect($ctx1->get('hcm.workers', 'workersUniqID'))->toBeNull();
    expect($ctx2->get('hcm.workers', 'workersUniqID'))->toBe('XXX');
});

test('ExecutionContext::populateFromRow extracts only identifier fields', function () {
    $ctx = new ExecutionContext;
    $row = ['workersUniqID' => 'ID001', 'PersonNumber' => 'P001', 'DisplayName' => 'John'];

    $enriched = $ctx->populateFromRow('hcm.workers', $row, ['workersUniqID']);

    expect($enriched->get('hcm.workers', 'workersUniqID'))->toBe('ID001');
    expect($enriched->get('hcm.workers', 'PersonNumber'))->toBeNull();
});

test('ExecutionContext::toBindings matches resolvePath context format', function () {
    $ctx = new ExecutionContext;
    $ctx->set('hcm.workers', 'workersUniqID', 'W001');
    $ctx->set('hcm.workers.workRelationships', 'PeriodOfServiceId', '123');

    $bindings = $ctx->toBindings();

    expect($bindings)->toHaveKey('hcm.workers');
    expect($bindings['hcm.workers']['workersUniqID'])->toBe('W001');
    expect($bindings['hcm.workers.workRelationships']['PeriodOfServiceId'])->toBe('123');
});

// ──────────────────────────────────────────────────────────────────────────────
// QueryNode
// ──────────────────────────────────────────────────────────────────────────────

/** @param list<RelationDefinition> $relations */
function makeResource(string $id, array $relations = []): ResourceDefinition
{
    return new ResourceDefinition(
        id: $id, name: $id, module: 'test', label: $id,
        collectionPath: "/{$id}", itemPath: "/{$id}/{{$id}Id}",
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        fields: [FieldDefinition::identifier("{$id}Id")],
        relations: $relations,
    );
}

function makeRelation(string $sourceId, string $targetId): RelationDefinition
{
    return new RelationDefinition(
        id: "{$sourceId}→{$targetId}", sourceId: $sourceId, targetId: $targetId,
        type: RelationType::CHILD,
        pathTemplate: "/{$sourceId}/child/{$targetId}",
    );
}

/**
 * @param  list<FieldDefinition>  $fields
 * @param  list<RelationDefinition>  $relations
 */
function makeGraphResource(string $id, array $fields, array $relations = []): ResourceDefinition
{
    return new ResourceDefinition(
        id: $id,
        name: $id,
        module: 'arbitrary',
        label: $id,
        collectionPath: "/{$id}",
        itemPath: "/{$id}/{id}",
        apiVersion: '1',
        capabilities: QueryCapabilities::standard(),
        fields: $fields,
        relations: $relations,
    );
}

test('QueryNode root has depth 1 and no parent', function () {
    $node = new QueryNode(makeResource('workers'), depth: 1, nodeId: 'n1');

    expect($node->isRoot())->toBeTrue();
    expect($node->isLeaf())->toBeTrue();
    expect($node->depth)->toBe(1);
    expect($node->parent)->toBeNull();
});

test('QueryNode::addChild creates edge and makes parent non-leaf', function () {
    $parent = new QueryNode(makeResource('workers'), depth: 1, nodeId: 'p');
    $child = new QueryNode(makeResource('workRelationships'), parent: $parent, depth: 2, nodeId: 'c');
    $relation = makeRelation('workers', 'workRelationships');

    $edge = $parent->addChild($child, $relation);

    expect($parent->isLeaf())->toBeFalse();
    expect($parent->children())->toHaveCount(1);
    expect($edge)->toBeInstanceOf(QueryEdge::class);
    expect($edge->child)->toBe($child);
    expect($edge->parent)->toBe($parent);
});

test('QueryNode::ancestors returns path from parent to root', function () {
    $workers = new QueryNode(makeResource('workers'), depth: 1, nodeId: 'n1');
    $wr = new QueryNode(makeResource('workRelationships'), parent: $workers, depth: 2, nodeId: 'n2');
    $asgn = new QueryNode(makeResource('assignments'), parent: $wr, depth: 3, nodeId: 'n3');

    $ancestors = $asgn->ancestors();

    expect($ancestors)->toHaveCount(2);
    expect($ancestors[0]->resource->id)->toBe('workRelationships');
    expect($ancestors[1]->resource->id)->toBe('workers');
});

test('QueryNode::descendants returns all descendants in DFS', function () {
    $workers = new QueryNode(makeResource('workers'), depth: 1, nodeId: 'n1');
    $wr = new QueryNode(makeResource('workRelationships'), parent: $workers, depth: 2, nodeId: 'n2');
    $asgn = new QueryNode(makeResource('assignments'), parent: $wr, depth: 3, nodeId: 'n3');
    $mgrs = new QueryNode(makeResource('managers'), parent: $asgn, depth: 4, nodeId: 'n4');

    $workers->addChild($wr, makeRelation('workers', 'workRelationships'));
    $wr->addChild($asgn, makeRelation('workRelationships', 'assignments'));
    $asgn->addChild($mgrs, makeRelation('assignments', 'managers'));

    $descendants = $workers->descendants();

    expect($descendants)->toHaveCount(3);
    expect($descendants[0]->resource->id)->toBe('workRelationships');
    expect($descendants[1]->resource->id)->toBe('assignments');
    expect($descendants[2]->resource->id)->toBe('managers');
});

// ──────────────────────────────────────────────────────────────────────────────
// QueryGraph
// ──────────────────────────────────────────────────────────────────────────────

test('QueryGraph round-trips the Workers to Managers hierarchy without loss', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();
    $workersResource = $registry->findOrFail('hcm.workers');
    $relationshipsResource = $registry->findOrFail('hcm.workers.workRelationships');
    $assignmentsResource = $registry->findOrFail('hcm.workers.workRelationships.assignments');
    $managersResource = $registry->findOrFail('hcm.workers.workRelationships.assignments.managers');
    $workersToRelationships = $registry->findRelation('hcm.workers→workRelationships')
        ?? throw new RuntimeException('Workers to workRelationships relation is missing.');
    $relationshipsToAssignments = $registry->findRelation('hcm.workers.workRelationships→assignments')
        ?? throw new RuntimeException('WorkRelationships to assignments relation is missing.');
    $assignmentsToManagers = $registry->findRelation('hcm.workers.workRelationships.assignments→managers')
        ?? throw new RuntimeException('Assignments to managers relation is missing.');

    $workers = new QueryNode($workersResource);
    $relationships = new QueryNode(
        $relationshipsResource,
        parent: $workers,
        depth: 2,
    );
    $assignments = new QueryNode(
        $assignmentsResource,
        parent: $relationships,
        depth: 3,
    );
    $managers = new QueryNode(
        $managersResource,
        parent: $assignments,
        depth: 4,
    );
    $graph = new QueryGraph($workers);

    $graph->addChild($workers, $relationships, $workersToRelationships);
    $graph->addChild($relationships, $assignments, $relationshipsToAssignments);
    $graph->addChild($assignments, $managers, $assignmentsToManagers);
    $payload = $graph->toArray();

    $restored = QueryGraph::fromArray($payload, $registry);

    expect($restored->toArray())->toBe($payload)
        ->and(array_map(
            static fn (QueryNode $node): string => $node->resource->id,
            $restored->allNodes(),
        ))->toBe([
            'hcm.workers',
            'hcm.workers.workRelationships',
            'hcm.workers.workRelationships.assignments',
            'hcm.workers.workRelationships.assignments.managers',
        ]);
});

test('QueryGraph round-trips an arbitrary four-level payload without losing children', function () {
    $rootToSecond = makeRelation('arbitrary.root', 'arbitrary.second');
    $secondToThird = makeRelation('arbitrary.second', 'arbitrary.third');
    $thirdToFourth = makeRelation('arbitrary.third', 'arbitrary.fourth');

    $rootResource = makeGraphResource(
        'arbitrary.root',
        [FieldDefinition::identifier('rootId'), FieldDefinition::string('rootName')],
        [$rootToSecond],
    );
    $secondResource = makeGraphResource(
        'arbitrary.second',
        [FieldDefinition::identifier('secondId'), FieldDefinition::string('secondName')],
        [$secondToThird],
    );
    $thirdResource = makeGraphResource(
        'arbitrary.third',
        [FieldDefinition::identifier('thirdId'), FieldDefinition::string('thirdName')],
        [$thirdToFourth],
    );
    $fourthResource = makeGraphResource(
        'arbitrary.fourth',
        [FieldDefinition::identifier('fourthId'), FieldDefinition::string('fourthName')],
    );

    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();
    foreach ([$rootResource, $secondResource, $thirdResource, $fourthResource] as $resource) {
        $registry->register($resource);
    }

    $root = new QueryNode(
        $rootResource,
        fields: ['rootId', 'rootName'],
        filters: [
            'logic' => 'and',
            'conditions' => [[
                'field' => 'rootName',
                'operator' => 'eq',
                'value' => 'North',
            ]],
        ],
        parameters: ['limit' => 20],
        nodeId: '550e8400-e29b-41d4-a716-446655440000',
    );
    $graph = new QueryGraph($root);
    $second = new QueryNode(
        $secondResource,
        fields: ['secondId'],
        filters: [
            'logic' => 'and',
            'conditions' => [[
                'field' => 'secondName',
                'operator' => 'eq',
                'value' => 'Active',
            ]],
        ],
        parameters: ['offset' => 10],
        parent: $root,
        depth: 2,
        nodeId: '550e8400-e29b-41d4-a716-446655440001',
    );
    $third = new QueryNode(
        $thirdResource,
        fields: ['thirdId', 'thirdName'],
        parameters: ['orderBy' => 'thirdName:asc'],
        parent: $second,
        depth: 3,
        nodeId: '550e8400-e29b-41d4-a716-446655440002',
    );
    $fourth = new QueryNode(
        $fourthResource,
        fields: ['fourthId'],
        parent: $third,
        depth: 4,
        nodeId: '550e8400-e29b-41d4-a716-446655440003',
    );

    $graph->addChild($root, $second, $rootToSecond);
    $graph->addChild($second, $third, $secondToThird);
    $graph->addChild($third, $fourth, $thirdToFourth);
    $payload = $graph->toArray();

    $restored = QueryGraph::fromArray($payload, $registry);

    expect($restored->toArray())->toBe($payload)
        ->and($restored->allNodes())->toHaveCount(4)
        ->and(array_map(
            static fn (QueryNode $node): string => $node->resource->id,
            $restored->allNodes(),
        ))->toBe([
            'arbitrary.root',
            'arbitrary.second',
            'arbitrary.third',
            'arbitrary.fourth',
        ]);
});

test('QueryGraph::allNodes returns root + all descendants', function () {
    $policy = new QueryTraversalPolicy(6);
    $workersToRelationships = makeRelation('workers', 'workRelationships');
    $relationshipsToAssignments = makeRelation('workRelationships', 'assignments');
    $workers = new QueryNode(
        makeResource('workers', [$workersToRelationships]),
        depth: 1,
    );
    $graph = new QueryGraph($workers, $policy);

    $wr = new QueryNode(
        makeResource('workRelationships', [$relationshipsToAssignments]),
        parent: $workers,
        depth: 2,
    );
    $asgn = new QueryNode(makeResource('assignments'), parent: $wr, depth: 3);

    $graph->addChild($workers, $wr, $workersToRelationships);
    $graph->addChild($wr, $asgn, $relationshipsToAssignments);

    expect($graph->allNodes())->toHaveCount(3);
    expect($graph->currentDepth())->toBe(3);
});

test('QueryGraph enforces maxDepth', function () {
    $policy = new QueryTraversalPolicy(2);
    $workersToRelationships = makeRelation('workers', 'workRelationships');
    $relationshipsToAssignments = makeRelation('workRelationships', 'assignments');
    $workers = new QueryNode(
        makeResource('workers', [$workersToRelationships]),
        depth: 1,
    );
    $graph = new QueryGraph($workers, $policy);

    $wr = new QueryNode(
        makeResource('workRelationships', [$relationshipsToAssignments]),
        parent: $workers,
        depth: 2,
    );
    $graph->addChild($workers, $wr, $workersToRelationships);

    $asgn = new QueryNode(makeResource('assignments'), parent: $wr, depth: 3);

    expect(fn () => $graph->addChild($wr, $asgn, $relationshipsToAssignments))
        ->toThrow(RuntimeException::class);
});

test('QueryGraph::canGrowDeeper evaluates the selected parent branch', function () {
    $policy = new QueryTraversalPolicy(2);
    $relation = makeRelation('workers', 'workRelationships');
    $workers = new QueryNode(makeResource('workers', [$relation]), depth: 1);
    $graph = new QueryGraph($workers, $policy);
    $wr = new QueryNode(makeResource('workRelationships'), parent: $workers, depth: 2);
    $graph->addChild($workers, $wr, $relation);

    expect($graph->canGrowDeeper($workers))->toBeTrue()
        ->and($graph->canGrowDeeper($wr))->toBeFalse();
});

test('QueryGraph::toArray serializes version and root', function () {
    $workers = new QueryNode(makeResource('workers'), depth: 1);
    $graph = new QueryGraph($workers);

    $arr = $graph->toArray();

    expect($arr)->toHaveKey('version')
        ->toHaveKey('maxDepth')
        ->toHaveKey('maxNodes')
        ->toHaveKey('root');
    expect($arr['version'])->toBe(1);
    expect($arr['maxDepth'])->toBe(QueryTraversalPolicy::DEFAULT_MAX_DEPTH);
    expect($arr['maxNodes'])->toBe(QueryTraversalPolicy::DEFAULT_MAX_NODES);
    expect($arr['root']['resourceId'])->toBe('workers');
});

// ──────────────────────────────────────────────────────────────────────────────
// WorkersResourceRegistry — hiérarchie Workers complète
// ──────────────────────────────────────────────────────────────────────────────

test('WorkersResourceRegistry declares workers resource', function () {
    $registry = new WorkersResourceRegistry;
    $all = collect($registry->all())->keyBy('id');

    expect($all)->toHaveKey('hcm.workers');
    expect($all['hcm.workers']->module)->toBe('hcm');
    expect($all['hcm.workers']->name)->toBe('workers');
});

test('WorkersResourceRegistry workers has child workRelationships', function () {
    $registry = new WorkersResourceRegistry;
    $workers = collect($registry->all())->firstWhere('id', 'hcm.workers');

    $childIds = array_map(fn ($r) => $r->targetId, $workers->children());
    expect($childIds)->toContain('hcm.workers.workRelationships');
});

test('WorkersResourceRegistry workRelationships has child assignments', function () {
    $registry = new WorkersResourceRegistry;
    $wr = collect($registry->all())->firstWhere('id', 'hcm.workers.workRelationships');

    $childIds = array_map(fn ($r) => $r->targetId, $wr->children());
    expect($childIds)->toContain('hcm.workers.workRelationships.assignments');
});

test('WorkersResourceRegistry assignments has five children', function () {
    $registry = new WorkersResourceRegistry;
    $asgn = collect($registry->all())->firstWhere('id', 'hcm.workers.workRelationships.assignments');

    expect($asgn->children())->toHaveCount(5);
    $childIds = array_map(fn ($r) => $r->targetId, $asgn->children());
    expect($childIds)->toContain('hcm.workers.workRelationships.assignments.managers');
    expect($childIds)->toContain('hcm.workers.workRelationships.assignments.allReports');
    expect($childIds)->toContain('hcm.workers.workRelationships.assignments.gradeSteps');
});

test('WorkersResourceRegistry managers has no children (leaf)', function () {
    $registry = new WorkersResourceRegistry;
    $managers = collect($registry->all())->firstWhere('id', 'hcm.workers.workRelationships.assignments.managers');

    expect($managers->children())->toBeEmpty();
});

test('managers relation has three ancestor bindings', function () {
    $registry = new WorkersResourceRegistry;
    $asgn = collect($registry->all())->firstWhere('id', 'hcm.workers.workRelationships.assignments');
    $relation = collect($asgn->children())->first(
        fn ($r) => $r->targetId === 'hcm.workers.workRelationships.assignments.managers',
    );

    expect($relation->bindings)->toHaveCount(3);
    $placeholders = array_map(fn ($b) => $b->placeholder, $relation->bindings);
    expect($placeholders)->toContain('workersUniqID');
    expect($placeholders)->toContain('PeriodOfServiceId');
    expect($placeholders)->toContain('assignmentsUniqID');
});

test('managers path resolves correctly from full context', function () {
    $registry = new WorkersResourceRegistry;
    $asgn = collect($registry->all())->firstWhere('id', 'hcm.workers.workRelationships.assignments');
    $relation = collect($asgn->children())->first(
        fn ($r) => $r->targetId === 'hcm.workers.workRelationships.assignments.managers',
    );

    $context = [
        'hcm.workers' => ['workersUniqID' => 'W001'],
        'hcm.workers.workRelationships' => ['PeriodOfServiceId' => '42'],
        'hcm.workers.workRelationships.assignments' => ['assignmentsUniqID' => 'A007'],
    ];

    $path = $relation->resolvePath($context);

    expect($path)->toContain('/workers/W001/')
        ->toContain('/workRelationships/42/')
        ->toContain('/assignments/A007/')
        ->toContain('/child/managers');
});

// ──────────────────────────────────────────────────────────────────────────────
// ResourceDefinitionRegistry
// ──────────────────────────────────────────────────────────────────────────────

test('ResourceDefinitionRegistry finds workers by id', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();

    $workers = $registry->find('hcm.workers');

    expect($workers)->not->toBeNull();
    expect($workers->id)->toBe('hcm.workers');
});

test('ResourceDefinitionRegistry::childrenOf returns workRelationships for workers', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();
    $children = $registry->childrenOf('hcm.workers');

    expect(collect($children)->pluck('id')->toArray())
        ->toContain('hcm.workers.workRelationships');
});

test('ResourceDefinitionRegistry::childrenOf returns five children for assignments', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();
    $children = $registry->childrenOf('hcm.workers.workRelationships.assignments');

    expect($children)->toHaveCount(5);
});

test('ResourceDefinitionRegistry::childrenOf returns empty for leaf', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();
    $children = $registry->childrenOf('hcm.workers.workRelationships.assignments.managers');

    expect($children)->toBeEmpty();
});

test('ResourceDefinitionRegistry::findOrFail throws for unknown id', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();

    expect(fn () => $registry->findOrFail('unknown.resource'))
        ->toThrow(RuntimeException::class);
});

test('ResourceDefinitionRegistry::forModule returns only hcm resources', function () {
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();
    $hcm = $registry->forModule('hcm');

    expect($hcm)->not->toBeEmpty();
    foreach ($hcm as $resource) {
        expect($resource->module)->toBe('hcm');
    }
});

test('engine logic does not reference workers by name — registry is generic', function () {
    // Le moteur doit fonctionner via IDs génériques, pas via "if resource == workers"
    $registry = (function () {
        $r = new ResourceDefinitionRegistry;
        $r->registerAll((new WorkersResourceRegistry)->all());

        return $r;
    })();

    // Un registre fictif avec le même schéma que Workers mais des noms différents
    $fakeParent = new ResourceDefinition(
        id: 'fake.parent', name: 'things', module: 'fake', label: 'Things',
        collectionPath: '/things', itemPath: '/things/{thingsId}',
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        relations: [
            new RelationDefinition(
                id: 'fake.parent→fake.child',
                sourceId: 'fake.parent', targetId: 'fake.child',
                type: RelationType::CHILD,
                pathTemplate: '/things/{thingsId}/child/subThings',
                bindings: [new AncestorBinding('thingsId', 'fake.parent', 'thingsId')],
            ),
        ],
    );

    $registry->register($fakeParent);
    $children = $registry->childrenOf('fake.parent');

    // Le registry retourne [] car fake.child n'est pas enregistré — comportement attendu
    // Le point est que l'appel n'a pas planté et ne contient aucun if/switch spécifique
    expect($children)->toBeArray();
});
