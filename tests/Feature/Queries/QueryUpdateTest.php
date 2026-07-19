<?php

use App\Enums\QueryAccessLevel;
use App\Models\Query;

test('owner can visit the edit page', function () {
    $owner = createConnectedUser([], ['key' => 'client_x']);
    $query = Query::factory()->create([
        'user_id' => $owner->id,
        'mode' => 'single',
        'description' => 'Description à conserver pendant la modification',
    ]);

    $this->actingAs($owner)
        ->withoutVite()
        ->get(route('queries.edit', $query))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('queries/edit')
            ->where('query.description', 'Description à conserver pendant la modification'));
});

test('non-owner cannot visit the edit page', function () {
    $other = createConnectedUser([], ['key' => 'client_x']);
    $query = Query::factory()->create(['mode' => 'single']);

    $this->actingAs($other)
        ->get(route('queries.edit', $query))
        ->assertForbidden();
});

test('owner can update a query', function () {
    $owner = createConnectedUser([], ['key' => 'client_x']);
    $tenant = $owner->oracleTenants()->sole();
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
            'access_level' => 'organization',
            'parameters' => [
                'limit' => 50,
                'resource_key' => 'suppliers',
            ],
        ])
        ->assertRedirect(route('queries.show', $query));

    expect($query->fresh()->name)->toBe('Updated name');
    expect($query->fresh()->description)->toBe('Updated description');
    expect($query->fresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);
    expect($query->fresh()->parameters['resource_key'])->toBe('suppliers');
    expect($query->fresh()->oracle_tenant_id)->toBe($tenant->id);
});

test('non-owner cannot update a query', function () {
    $other = createConnectedUser([], ['key' => 'client_x']);
    $query = Query::factory()->create(['mode' => 'single']);

    $this->actingAs($other)
        ->put(route('queries.update', $query), [
            'name' => 'Hacked name',
            'description' => null,
            'mode' => 'single',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
            'tenant_key' => 'client_x',
            'access_level' => 'private',
        ])
        ->assertForbidden();
});

test('update validation rejects invalid tenant', function () {
    $owner = createConnectedUser([], ['key' => 'client_x']);
    $query = Query::factory()->create(['user_id' => $owner->id, 'mode' => 'single']);

    $this->actingAs($owner)
        ->putJson(route('queries.update', $query), [
            'name' => 'X',
            'mode' => 'single',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
            'tenant_key' => 'unknown_tenant',
            'access_level' => 'private',
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
        'access_level' => 'private',
    ])->assertUnauthorized();
});
