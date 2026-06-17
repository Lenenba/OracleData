<?php

use App\Models\Query;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot reach the create form or store a query', function () {
    $this->get(route('queries.create'))->assertRedirect(route('login'));
    $this->post(route('queries.store'), [])->assertRedirect(route('login'));
});

test('the create page renders', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('queries.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('queries/create'));
});

test('an authenticated user can store a query', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Liste des employés',
        'description' => 'Tous les workers HCM',
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'parameters' => ['limit' => 25],
        'visibility' => 'private',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('queries.index'));

    $query = Query::sole();
    expect($query->user_id)->toBe($user->id)
        ->and($query->name)->toBe('Liste des employés')
        ->and($query->tenant_key)->toBe('client_x')
        ->and($query->parameters)->toBe(['limit' => 25])
        ->and($query->visibility)->toBe('private');
});

test('an authenticated user can store a resolved single supplier query', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Fournisseurs actifs Acme',
        'description' => 'Donne-moi les 5 fournisseurs actifs dont le nom contient Acme',
        'mode' => 'single',
        'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
        'tenant_key' => 'client_x',
        'parameters' => [
            'limit' => 5,
            'q' => "Status='ACTIVE' AND Supplier LIKE '%Acme%'",
            'fields' => 'Supplier,SupplierNumber',
        ],
        'visibility' => 'private',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('queries.index'));

    $query = Query::sole();
    expect($query->user_id)->toBe($user->id)
        ->and($query->mode)->toBe('single')
        ->and($query->resource_path)->toBe('/fscmRestApi/resources/11.13.18.05/suppliers')
        ->and($query->tenant_key)->toBe('client_x')
        ->and($query->parameters)->toBe([
            'limit' => 5,
            'q' => "Status='ACTIVE' AND Supplier LIKE '%Acme%'",
            'fields' => 'Supplier,SupplierNumber',
        ]);
});

test('an authenticated user can store an agent analysis query without a resource path', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Total facturé par fournisseur',
        'description' => 'Lier les fournisseurs et les factures et donner le total facturé par fournisseur',
        'mode' => 'agent',
        'tenant_key' => 'client_x',
        'visibility' => 'shared',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('queries.index'));

    $query = Query::sole();
    expect($query->mode)->toBe('agent')
        ->and($query->resource_path)->toBeNull()
        ->and($query->parameters)->toBeNull()
        ->and($query->description)->toContain('factures')
        ->and($query->visibility)->toBe('shared');
});

test('a name is required', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('queries.store'), [
            'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
            'visibility' => 'private',
        ])
        ->assertInvalid('name');

    expect(Query::count())->toBe(0);
});

test('resource_path must start with an allowed Fusion prefix', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('queries.store'), [
            'name' => 'Malicious',
            'resource_path' => 'https://evil.example.com/data',
            'visibility' => 'private',
        ])
        ->assertInvalid('resource_path');

    expect(Query::count())->toBe(0);
});

test('a path not starting with a slash is rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('queries.store'), [
            'name' => 'Bad path',
            'resource_path' => 'hcmRestApi/resources/workers',
            'visibility' => 'private',
        ])
        ->assertInvalid('resource_path');
});

test('visibility must be private or shared', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('queries.store'), [
            'name' => 'Bad visibility',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
            'visibility' => 'public',
        ])
        ->assertInvalid('visibility');
});

test('tenant_key must be configured', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('queries.store'), [
            'name' => 'Bad tenant',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
            'tenant_key' => 'unknown',
            'visibility' => 'private',
        ])
        ->assertInvalid('tenant_key');
});

test('unknown parameter keys are stripped before saving', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'With junk params',
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'parameters' => ['limit' => 10, 'evil' => 'DROP TABLE', 'q' => 'foo'],
        'visibility' => 'private',
    ])->assertSessionHasNoErrors();

    expect(Query::sole()->parameters)->toBe(['limit' => 10, 'q' => 'foo']);
});
