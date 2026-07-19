<?php

use App\Enums\OracleExecutionPolicy;
use App\Enums\QueryAccessLevel;
use App\Models\Category;
use App\Models\Query;
use App\Models\Tag;
use App\Models\User;

test('a user clones a shared query onto their own default connection', function () {
    $owner = User::factory()->create();
    $user = createConnectedUser([], [
        'key' => 'reader_default',
        'label' => 'Reader default',
    ]);
    $readerTenant = $user->oracleTenants()->sole();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();

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
        'category_id' => $category->id,
    ]);
    $source->tags()->attach($tag);

    $this->actingAs($user)
        ->post(route('queries.clone', $source))
        ->assertRedirect();

    $copy = Query::query()->where('user_id', $user->id)->sole();

    expect($copy->name)->toBe('Copie de Bons de commande ouverts')
        ->and($copy->description)->toBe($source->description)
        ->and($copy->resource_path)->toBe($source->resource_path)
        ->and($copy->tenant_key)->toBe('reader_default')
        ->and($copy->oracle_tenant_id)->toBe($readerTenant->id)
        ->and($copy->mode)->toBe($source->mode)
        ->and($copy->parameters)->toBe($source->parameters)
        ->and($copy->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and($copy->category_id)->toBe($category->id)
        ->and($copy->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

test('a user can clone their own private query', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $source = Query::factory()->for($user)->private()->create([
        'name' => 'Ma requête',
        'oracle_tenant_id' => $user->oracleTenants()->sole()->id,
    ]);

    $this->actingAs($user)
        ->post(route('queries.clone', $source))
        ->assertRedirect();

    expect(Query::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('a cloned query preserves its execution policy', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $source = Query::factory()->for($user)->private()->create([
        'name' => 'Requête exacte',
        'oracle_tenant_id' => $user->oracleTenants()->sole()->id,
        'execution_policy' => OracleExecutionPolicy::EXACT,
    ]);

    $this->actingAs($user)
        ->post(route('queries.clone', $source))
        ->assertRedirect();

    $copy = Query::query()
        ->where('user_id', $user->id)
        ->whereKeyNot($source->id)
        ->sole();

    expect($copy->execution_policy)->toBe(OracleExecutionPolicy::EXACT);
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
