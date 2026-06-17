<?php

use App\Models\Query;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic', [
        'api_key' => 'sk-test',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-opus-4-8',
        'version' => '2023-06-01',
    ]);
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

test('a single-resource request is resolved by the LLM and previewed', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'mode' => 'single',
                'query' => [
                    'resource' => 'suppliers',
                    'fields' => ['Supplier', 'SupplierNumber'],
                    'expand' => ['contacts', 'sites'],
                    'q' => "Status='ACTIVE'",
                    'limit' => 5,
                ],
            ])]],
            'stop_reason' => 'end_turn',
        ]),
        'client-x.fa.oraclecloud.com/*' => Http::response([
            'items' => [['Supplier' => 'Acme', 'SupplierNumber' => 'S-100']],
            'count' => 1,
            'hasMore' => false,
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.preview'), [
            'intent' => 'fournisseurs actifs avec nom, numéro, contacts et sites',
            'tenant' => 'client_x',
            'parameters' => ['limit' => 25],
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'single')
        ->assertJsonPath('tenant', 'client_x')
        ->assertJsonPath('resource.key', 'suppliers')
        ->assertJsonPath('error', null)
        ->assertJsonCount(1, 'items');

    Http::assertSent(function ($request) {
        if (! str_starts_with($request->url(), 'https://client-x.fa.oraclecloud.com')) {
            return false;
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        // expand demandé → fields est volontairement abandonné (sinon Oracle
        // masque les enfants contacts/sites).
        return ! isset($query['fields'])
            && $query['expand'] === 'contacts,sites'
            && $query['q'] === "Status='ACTIVE'"
            && $query['limit'] === '5';
    });
});

test('a multi-resource analysis is previewed through the agent', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['mode' => 'agent', 'plan' => 'joindre fournisseurs et factures'])]], 'stop_reason' => 'end_turn'])
            ->push(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'oracle_query', 'input' => ['resource' => 'invoices']]], 'stop_reason' => 'tool_use'])
            ->push(['content' => [['type' => 'tool_use', 'id' => 't2', 'name' => 'submit_result', 'input' => [
                'columns' => ['Supplier', 'Total'],
                'rows' => [['Supplier' => 'Acme', 'Total' => 1200]],
                'analysis' => 'Acme est le premier fournisseur par montant facturé.',
            ]]], 'stop_reason' => 'tool_use']),
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [['Supplier' => 'Acme']], 'count' => 1]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.preview'), [
            'intent' => 'lier les fournisseurs et les factures et analyser',
            'tenant' => 'client_x',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'agent')
        ->assertJsonPath('columns', ['Supplier', 'Total'])
        ->assertJsonPath('analysis', 'Acme est le premier fournisseur par montant facturé.')
        ->assertJsonPath('error', null)
        ->assertJsonCount(1, 'items')
        ->assertJsonCount(1, 'oracleCalls');
});

test('an ambiguous request returns a clarification question', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode(['mode' => 'clarify', 'question' => 'De quelle ressource parlez-vous ?'])]],
        'stop_reason' => 'end_turn',
    ])]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.preview'), [
            'intent' => 'donne-moi les trucs',
            'tenant' => 'client_x',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'clarify')
        ->assertJsonPath('clarification', 'De quelle ressource parlez-vous ?')
        ->assertJsonCount(0, 'items');
});

test('an agent query is executed by the agent at run time', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 'r1', 'name' => 'oracle_query', 'input' => ['resource' => 'suppliers']]], 'stop_reason' => 'tool_use'])
            ->push(['content' => [['type' => 'tool_use', 'id' => 'r2', 'name' => 'submit_result', 'input' => [
                'columns' => ['Supplier'],
                'rows' => [['Supplier' => 'Acme']],
                'analysis' => 'ok',
            ]]], 'stop_reason' => 'tool_use']),
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [['Supplier' => 'Acme']], 'count' => 1]),
    ]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->agent()->create();

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk()
        ->assertJsonPath('mode', 'agent')
        ->assertJsonPath('analysis', 'ok')
        ->assertJsonPath('error', null)
        ->assertJsonCount(1, 'items');
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
