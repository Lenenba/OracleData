<?php

use App\Models\Query;
use App\Models\User;

test('a user can clone a shared query and becomes the owner of a private copy', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();

    $source = Query::factory()->for($owner)->shared()->create([
        'name' => 'Bons de commande ouverts',
        'description' => 'PO ouverts avec fournisseur',
        'resource_path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrders',
        'tenant_key' => 'client_x',
        'mode' => 'single',
        'parameters' => [
            'resource_key' => 'purchase_orders',
            'fields' => 'OrderNumber,Supplier,Status',
            'limit' => 25,
        ],
    ]);

    $this->actingAs($user)
        ->post(route('queries.clone', $source))
        ->assertRedirect();

    $copy = Query::query()->where('user_id', $user->id)->sole();

    expect($copy->name)->toBe('Copie de Bons de commande ouverts')
        ->and($copy->description)->toBe($source->description)
        ->and($copy->resource_path)->toBe($source->resource_path)
        ->and($copy->tenant_key)->toBe($source->tenant_key)
        ->and($copy->mode)->toBe($source->mode)
        ->and($copy->parameters)->toBe($source->parameters)
        ->and($copy->visibility)->toBe('private');
});

test('a user can clone their own private query', function () {
    $user = User::factory()->create();
    $source = Query::factory()->for($user)->private()->create(['name' => 'Ma requête']);

    $this->actingAs($user)
        ->post(route('queries.clone', $source))
        ->assertRedirect();

    expect(Query::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('a user cannot clone another users private query', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $source = Query::factory()->for($owner)->private()->create();

    $this->actingAs($user)
        ->post(route('queries.clone', $source))
        ->assertForbidden();

    expect(Query::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('guests cannot clone a query', function () {
    $source = Query::factory()->shared()->create();

    $this->post(route('queries.clone', $source))->assertRedirect(route('login'));
});
