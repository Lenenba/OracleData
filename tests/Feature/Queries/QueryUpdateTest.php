<?php

use App\Models\Query;
use App\Models\User;

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

test('owner can visit the edit page', function () {
    $owner = User::factory()->create();
    $query = Query::factory()->create(['user_id' => $owner->id, 'mode' => 'single']);

    $this->actingAs($owner)
        ->withoutVite()
        ->get(route('queries.edit', $query))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('queries/edit'));
});

test('non-owner cannot visit the edit page', function () {
    $other = User::factory()->create();
    $query = Query::factory()->create(['mode' => 'single']);

    $this->actingAs($other)
        ->get(route('queries.edit', $query))
        ->assertForbidden();
});

test('owner can update a query', function () {
    $owner = User::factory()->create();
    $query = Query::factory()->create([
        'user_id' => $owner->id,
        'mode' => 'single',
        'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
        'tenant_key' => 'client_x',
        'name' => 'Old name',
    ]);

    $this->actingAs($owner)
        ->put(route('queries.update', $query), [
            'name' => 'Updated name',
            'description' => 'Updated description',
            'mode' => 'single',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
            'tenant_key' => 'client_x',
            'visibility' => 'shared',
            'parameters' => [
                'limit' => 50,
                'resource_key' => 'suppliers',
            ],
        ])
        ->assertRedirect(route('queries.show', $query));

    expect($query->fresh()->name)->toBe('Updated name');
    expect($query->fresh()->visibility)->toBe('shared');
    expect($query->fresh()->parameters['resource_key'])->toBe('suppliers');
});

test('non-owner cannot update a query', function () {
    $other = User::factory()->create();
    $query = Query::factory()->create(['mode' => 'single']);

    $this->actingAs($other)
        ->put(route('queries.update', $query), [
            'name' => 'Hacked name',
            'description' => null,
            'mode' => 'single',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
            'tenant_key' => 'client_x',
            'visibility' => 'private',
        ])
        ->assertForbidden();
});

test('update validation rejects invalid tenant', function () {
    $owner = User::factory()->create();
    $query = Query::factory()->create(['user_id' => $owner->id, 'mode' => 'single']);

    $this->actingAs($owner)
        ->putJson(route('queries.update', $query), [
            'name' => 'X',
            'mode' => 'single',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
            'tenant_key' => 'unknown_tenant',
            'visibility' => 'private',
        ])
        ->assertUnprocessable();
});

test('guests cannot update a query', function () {
    $query = Query::factory()->create(['mode' => 'single']);

    $this->putJson(route('queries.update', $query), [
        'name' => 'X',
        'mode' => 'single',
        'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
        'tenant_key' => 'client_x',
        'visibility' => 'private',
    ])->assertUnauthorized();
});
