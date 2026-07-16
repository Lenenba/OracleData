<?php

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
    ]);
});

test('guests cannot call direct-preview', function () {
    $this->postJson(route('queries.direct-preview'), [
        'resource_key' => 'suppliers',
        'tenant' => 'client_x',
    ])->assertUnauthorized();
});

test('direct-preview returns rows without calling the LLM', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/*' => Http::response([
            'items' => [
                ['SupplierId' => 1, 'Supplier' => 'Acme', 'links' => []],
                ['SupplierId' => 2, 'Supplier' => 'Beta', 'links' => []],
            ],
            'count' => 2,
            'hasMore' => false,
        ], 200),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'client_x',
            'limit' => 10,
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'single')
        ->assertJsonPath('count', 2)
        ->assertJsonPath('error', null);
});

test('direct-preview projects only selected fields', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/*' => Http::response([
            'items' => [
                ['SupplierId' => 1, 'Supplier' => 'Acme', 'Status' => 'Active'],
            ],
            'count' => 1,
            'hasMore' => false,
        ], 200),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'client_x',
            'fields' => ['SupplierId', 'Supplier'],
            'limit' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('items.0.SupplierId', 1)
        ->assertJsonPath('items.0.Supplier', 'Acme');
});

test('direct-preview returns a clean error for an unknown resource', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'invented_resource',
            'tenant' => 'client_x',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'single')
        ->assertJsonStructure(['error']);
});

test('direct-preview rejects a tenant not in the list', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'unknown_tenant',
        ])
        ->assertUnprocessable();
});
