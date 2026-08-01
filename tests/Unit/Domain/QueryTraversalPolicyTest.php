<?php

use App\Domain\Query\QueryNode;
use App\Domain\Query\QueryTraversalPolicy;
use App\Services\Workers\WorkersResourceRegistry;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'fusion.query_graph.max_depth' => 6,
        'fusion.query_graph.max_nodes' => 50,
    ]);
});

test('policy uses the server configured limits by default', function () {
    config([
        'fusion.query_graph.max_depth' => 4,
        'fusion.query_graph.max_nodes' => 12,
    ]);

    $policy = new QueryTraversalPolicy;

    expect($policy->maxDepth)->toBe(4)
        ->and($policy->maxNodes)->toBe(12);
});

test('requested limits cannot exceed the server configured limits', function () {
    config([
        'fusion.query_graph.max_depth' => 3,
        'fusion.query_graph.max_nodes' => 8,
    ]);

    $policy = new QueryTraversalPolicy(maxDepth: 99, maxNodes: 999);

    expect($policy->maxDepth)->toBe(3)
        ->and($policy->maxNodes)->toBe(8);
});

test('policy evaluates growth from the selected branch instead of global graph depth', function () {
    config([
        'fusion.query_graph.max_depth' => 3,
        'fusion.query_graph.max_nodes' => 10,
    ]);

    $resource = (new WorkersResourceRegistry)->all()[0];
    $shortBranch = new QueryNode($resource, depth: 1, nodeId: 'short');
    $deepBranch = new QueryNode($resource, depth: 3, nodeId: 'deep');
    $policy = new QueryTraversalPolicy;

    expect($policy->canAddChildFor($shortBranch, currentNodeCount: 3))->toBeTrue()
        ->and($policy->canAddChildFor($deepBranch, currentNodeCount: 3))->toBeFalse();
});

test('policy blocks every branch when the configured node budget is exhausted', function () {
    config(['fusion.query_graph.max_nodes' => 3]);

    $resource = (new WorkersResourceRegistry)->all()[0];
    $branch = new QueryNode($resource, depth: 1, nodeId: 'branch');
    $policy = new QueryTraversalPolicy;

    expect($policy->canAddChildFor($branch, currentNodeCount: 2))->toBeTrue()
        ->and($policy->canAddChildFor($branch, currentNodeCount: 3))->toBeFalse();
});
