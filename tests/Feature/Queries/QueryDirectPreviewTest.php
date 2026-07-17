<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->queryUser = createConnectedUser([], [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
    ], [
        'identifier' => 'svc_x',
        'secret' => 'secret_x',
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

    $this->actingAs($this->queryUser)
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

    $this->actingAs($this->queryUser)
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

test('direct-preview executes joins and returns nested rows plus every Oracle call', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers*' => Http::response([
            'items' => [[
                'SupplierNumber' => '79768',
                'Supplier' => 'Acme',
                'sites' => ['items' => [['SupplierSite' => 'HQ', 'Email' => 'hq@acme.test']]],
            ]],
            'count' => 1,
        ]),
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/invoices*' => Http::response([
            'items' => [['SupplierNumber' => '79768', 'InvoiceNumber' => 'INV-10', 'InvoiceAmount' => 100]],
            'count' => 1,
        ]),
    ]);

    $this->actingAs($this->queryUser)
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'client_x',
            'expand' => ['sites'],
            'joins' => ['invoices'],
            'child_fields' => ['invoices' => ['InvoiceNumber', 'InvoiceAmount']],
            'limit' => 10,
        ])
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('items.0.invoices.0.InvoiceNumber', 'INV-10')
        ->assertJsonCount(2, 'oracleCalls')
        ->assertJsonPath('parameters.resource_key', 'suppliers')
        ->assertJsonPath('parameters.expand', 'sites')
        ->assertJsonPath('parameters.joins', 'invoices')
        ->assertJsonPath('parameters.child_fields.invoices.0', 'InvoiceNumber');
});

test('direct-preview returns a clean error when a join target is invalid', function () {
    $this->actingAs($this->queryUser)
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'client_x',
            'joins' => ['ghosts'],
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'single')
        ->assertJsonStructure(['error']);
});

test('direct-preview returns a clean error when Oracle fails, not a 500', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    $response = $this->actingAs($this->queryUser)
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'client_x',
        ])
        ->assertOk();

    expect($response->json('error'))->toBeString()
        ->and($response->json('items'))->toBe([]);
});

test('direct-preview returns a clean error for an unknown resource', function () {
    $this->actingAs($this->queryUser)
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'invented_resource',
            'tenant' => 'client_x',
        ])
        ->assertOk()
        ->assertJsonPath('mode', 'single')
        ->assertJsonStructure(['error']);
});

test('direct-preview rejects a tenant not in the list', function () {
    $this->actingAs($this->queryUser)
        ->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'unknown_tenant',
        ])
        ->assertUnprocessable();
});
