<?php

use App\Models\Query;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('fusion.default', 'client_x');
    config()->set('fusion.tenants', [
        'client_x' => [
            'label' => 'Client X',
            'base_url' => 'https://client-x.fa.oraclecloud.com',
            'username' => 'svc_x',
            'password' => 'secret_x',
        ],
        'client_y' => [
            'label' => 'Client Y',
            'base_url' => 'https://client-y.fa.oraclecloud.com',
            'username' => 'svc_y',
            'password' => 'secret_y',
        ],
    ]);
});

test('the owner can run a query and receives items with metadata', function () {
    Http::fake(['*' => Http::response([
        'items' => [['PersonId' => 1], ['PersonId' => 2]],
        'count' => 2,
        'hasMore' => true,
    ])]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'parameters' => ['limit' => 25],
    ]);

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk()
        ->assertJsonPath('tenant', 'client_x')
        ->assertJsonPath('count', 2)
        ->assertJsonPath('hasMore', true)
        ->assertJsonPath('error', null)
        ->assertJsonCount(2, 'items');
});

test('a natural language supplier query can be previewed', function () {
    Http::fake(['*' => Http::response([
        'items' => [
            ['SupplierId' => 100, 'Supplier' => 'Acme'],
            ['SupplierId' => 101, 'Supplier' => 'Globex'],
        ],
        'count' => 2,
        'hasMore' => false,
    ])]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.preview'), [
            'intent' => 'Je veux la liste des fournisseurs',
            'tenant' => 'client_x',
            'parameters' => ['limit' => 2],
        ])
        ->assertOk()
        ->assertJsonPath('tenant', 'client_x')
        ->assertJsonPath('resource.path', '/fscmRestApi/resources/11.13.18.05/suppliers')
        ->assertJsonPath('count', 2)
        ->assertJsonPath('error', null)
        ->assertJsonCount(2, 'items');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers')
        && str_contains($request->url(), 'limit=2'));
});

test('running targets the selected tenant base url', function () {
    Http::fake([
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [['t' => 'x']]]),
        'client-y.fa.oraclecloud.com/*' => Http::response(['items' => [['t' => 'y']]]),
    ]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_y'])
        ->assertOk()
        ->assertJsonPath('items.0.t', 'y');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://client-y.fa.oraclecloud.com'));
});

test('running falls back to the query tenant when none is submitted', function () {
    Http::fake([
        'client-y.fa.oraclecloud.com/*' => Http::response(['items' => [['t' => 'saved']]]),
    ]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create(['tenant_key' => 'client_y']);

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), [])
        ->assertOk()
        ->assertJsonPath('tenant', 'client_y')
        ->assertJsonPath('items.0.t', 'saved');
});

test('an unknown tenant is rejected', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'nope'])
        ->assertJsonValidationErrors('tenant');
});

test('a Fusion error is returned as a clean message, not a 500', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();

    expect($response->json('error'))->toBeString()
        ->and($response->json('items'))->toBe([]);
});

test('a shared query can be run by another user', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->shared()->create();

    $this->actingAs($other)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();
});

test('a private query cannot be run by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($other)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertForbidden();
});

test('guests cannot run a query', function () {
    $query = Query::factory()->create();

    // Web auth redirects unauthenticated users to login (the run XHR is only
    // ever issued by an authenticated session in the app).
    $this->post(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertRedirect(route('login'));
});
