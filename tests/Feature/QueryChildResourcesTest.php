<?php

use App\Models\User;

// ──────────────────────────────────────────────────────────────────────────────
// GET /queries/child-resources
// ──────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    config([
        'fusion.query_graph.resource_graph_enabled' => true,
        'fusion.query_graph.max_depth' => 6,
        'fusion.query_graph.max_nodes' => 50,
    ]);
});

test('child-resources is hidden when the resource graph flag is disabled', function () {
    config(['fusion.query_graph.resource_graph_enabled' => false]);

    $user = User::factory()->create();
    createOracleTenantFor($user);

    $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers&depth=1')
        ->assertNotFound();
});

test('child-resources returns children of workers', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers&depth=1');

    $response->assertSuccessful()
        ->assertJsonStructure([
            'resource' => ['id', 'name', 'label'],
            'children' => [
                '*' => ['relation', 'resource' => ['id', 'name', 'label', 'capabilities', 'fields']],
            ],
            'canGrowDeeper',
            'policy' => ['maxDepth', 'maxNodes'],
        ]);

    $childIds = collect($response->json('children'))->pluck('resource.id')->toArray();
    expect($childIds)->toContain('hcm.workers.workRelationships');
    expect($response->json('policy'))->toBe([
        'maxDepth' => 6,
        'maxNodes' => 50,
    ]);
});

test('child-resources returns children of workRelationships', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers.workRelationships&depth=2');

    $response->assertSuccessful();

    $childIds = collect($response->json('children'))->pluck('resource.id')->toArray();
    expect($childIds)->toContain('hcm.workers.workRelationships.assignments');
});

test('child-resources returns five children of assignments', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers.workRelationships.assignments&depth=3');

    $response->assertSuccessful();
    expect($response->json('children'))->toHaveCount(5);
});

test('child-resources returns empty children for leaf node managers', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers.workRelationships.assignments.managers&depth=4');

    $response->assertSuccessful();
    expect($response->json('children'))->toBeEmpty();
});

test('child-resources returns 404 for unknown resource', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=unknown.resource')
        ->assertNotFound();
});

test('child-resources blocks canGrowDeeper at depth 6', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers&depth=6');

    $response->assertSuccessful();
    expect($response->json('canGrowDeeper'))->toBeFalse()
        ->and($response->json('children'))->toBeEmpty();
});

test('child-resources cannot raise server limits through query parameters', function () {
    config([
        'fusion.query_graph.max_depth' => 3,
        'fusion.query_graph.max_nodes' => 4,
    ]);

    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers&depth=3&node_count=3&maxDepth=99&maxNodes=99');

    $response->assertSuccessful()
        ->assertJsonPath('canGrowDeeper', false)
        ->assertJsonPath('policy.maxDepth', 3)
        ->assertJsonPath('policy.maxNodes', 4)
        ->assertJsonCount(0, 'children');
});

test('child-resources stops offering children when the server node budget is exhausted', function () {
    config(['fusion.query_graph.max_nodes' => 3]);

    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers&depth=1&node_count=3');

    $response->assertSuccessful()
        ->assertJsonPath('canGrowDeeper', false)
        ->assertJsonPath('policy.maxNodes', 3)
        ->assertJsonCount(0, 'children');
});

test('child-resources requires authentication', function () {
    $this->getJson('/queries/child-resources?resource_id=hcm.workers')
        ->assertUnauthorized();
});

test('child-resources validates resource_id is required', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $this->actingAs($user)
        ->getJson('/queries/child-resources')
        ->assertUnprocessable();
});

test('child-resources response includes relation bindings for managers', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user);

    $response = $this->actingAs($user)
        ->getJson('/queries/child-resources?resource_id=hcm.workers.workRelationships.assignments&depth=3');

    $response->assertSuccessful();

    $managers = collect($response->json('children'))->firstWhere('resource.id', 'hcm.workers.workRelationships.assignments.managers');
    expect($managers)->not->toBeNull();
    expect($managers['relation']['bindings'])->toHaveCount(3);

    $placeholders = array_column($managers['relation']['bindings'], 'placeholder');
    expect($placeholders)->toContain('workersUniqID');
    expect($placeholders)->toContain('PeriodOfServiceId');
    expect($placeholders)->toContain('assignmentsUniqID');
});
