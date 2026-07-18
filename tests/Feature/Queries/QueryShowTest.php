<?php

use App\Models\Category;
use App\Models\Query;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can view the show page with tenant options', function () {
    $user = createConnectedUser([], ['key' => 'client_y', 'label' => 'Client Y']);
    $category = Category::factory()->withTranslation('fr', 'Finance')->create();
    $tag = Tag::factory()->withTranslation('fr', 'Mensuel')->create();
    $query = Query::factory()->for($user)->create([
        'tenant_key' => 'client_y',
        'oracle_tenant_id' => $user->oracleTenants()->sole()->id,
        'category_id' => $category->id,
    ]);
    $query->tags()->attach($tag);

    $this->actingAs($user)
        ->get(route('queries.show', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/show')
            ->where('query.id', $query->id)
            ->where('query.tenant_key', 'client_y')
            ->where('query.category.name', 'Finance')
            ->where('query.tags.0.name', 'Mensuel')
            ->where('defaultTenant', 'client_y')
            ->has('tenants')
        );
});

test('a shared query is shown with the readers tenant options', function () {
    $owner = User::factory()->create();
    $reader = createConnectedUser([], [
        'key' => 'reader_tenant',
        'label' => 'Reader tenant',
    ]);
    $query = Query::factory()->for($owner)->shared()->create([
        'tenant_key' => 'owner_tenant',
        'description' => 'Factures fournisseurs à contrôler chaque mois',
    ]);

    $this->actingAs($reader)
        ->get(route('queries.show', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('query.description', 'Factures fournisseurs à contrôler chaque mois')
            ->where('defaultTenant', 'reader_tenant')
            ->where('tenants', ['reader_tenant' => 'Reader tenant'])
        );
});

test('a private query is not viewable by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($other)
        ->get(route('queries.show', $query))
        ->assertForbidden();
});

test('guests cannot view the show page', function () {
    $query = Query::factory()->create();

    $this->get(route('queries.show', $query))->assertRedirect(route('login'));
});
