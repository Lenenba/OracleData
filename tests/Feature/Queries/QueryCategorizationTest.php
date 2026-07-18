<?php

use App\Models\Category;
use App\Models\Query;
use App\Models\Tag;
use Inertia\Testing\AssertableInertia as Assert;

test('a query can be stored with a category and free tags', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $category = Category::factory()->withTranslation('fr', 'Finance')->create();

    $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Factures ouvertes',
        'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
        'tenant_key' => 'client_x',
        'parameters' => ['limit' => 25],
        'visibility' => 'private',
        'category_id' => $category->id,
        'tags' => ['Comptes Fournisseurs', 'mensuel'],
    ])->assertSessionHasNoErrors()->assertRedirect(route('queries.index'));

    $query = Query::sole();

    expect($query->category_id)->toBe($category->id)
        ->and($query->tags()->pluck('slug')->all())
        ->toEqualCanonicalizing(['comptes-fournisseurs', 'mensuel']);
});

test('tags are reused by normalised slug instead of duplicated', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    Tag::factory()->create(['name' => 'Mensuel', 'slug' => 'mensuel']);

    $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Rapport mensuel',
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'visibility' => 'private',
        'tags' => ['MENSUEL'],
    ])->assertSessionHasNoErrors();

    expect(Tag::query()->count())->toBe(1)
        ->and(Query::sole()->tags()->pluck('slug')->all())->toBe(['mensuel']);
});

test('updating a query syncs its category and tags', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $category = Category::factory()->create();
    $query = Query::factory()->for($user)->create([
        'tenant_key' => 'client_x',
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
    ]);
    $query->tags()->attach(Tag::factory()->create(['slug' => 'obsolete', 'name' => 'Obsolète']));

    $this->actingAs($user)->put(route('queries.update', $query), [
        'name' => $query->name,
        'resource_path' => $query->resource_path,
        'tenant_key' => 'client_x',
        'visibility' => 'private',
        'category_id' => $category->id,
        'tags' => ['actif'],
    ])->assertSessionHasNoErrors();

    $query->refresh();

    expect($query->category_id)->toBe($category->id)
        ->and($query->tags()->pluck('slug')->all())->toBe(['actif']);
});

test('an unknown category is rejected', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);

    $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Requête',
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'visibility' => 'private',
        'category_id' => 999,
    ])->assertInvalid(['category_id']);
});

test('the library can be filtered by category slug', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $finance = Category::factory()->create(['slug' => 'finance']);
    $rh = Category::factory()->create(['slug' => 'rh']);
    Query::factory()->for($user)->create(['name' => 'Factures', 'category_id' => $finance->id]);
    Query::factory()->for($user)->create(['name' => 'Employés', 'category_id' => $rh->id]);

    $this->actingAs($user)
        ->get(route('queries.index', ['category' => 'finance']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/index')
            ->where('category', 'finance')
            ->count('queries.data', 1)
            ->where('queries.data.0.name', 'Factures'));
});

test('the library can be filtered by tag slug', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $monthly = Tag::factory()->create(['slug' => 'mensuel', 'name' => 'Mensuel']);
    $tagged = Query::factory()->for($user)->create(['name' => 'Rapport mensuel']);
    $tagged->tags()->attach($monthly);
    Query::factory()->for($user)->create(['name' => 'Rapport annuel']);

    $this->actingAs($user)
        ->get(route('queries.index', ['tag' => 'mensuel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tag', 'mensuel')
            ->count('queries.data', 1)
            ->where('queries.data.0.name', 'Rapport mensuel'));
});

test('the search also matches tag names', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $tag = Tag::factory()->create(['slug' => 'paie-quebec', 'name' => 'Paie Québec']);
    $tagged = Query::factory()->for($user)->create(['name' => 'Rapport RH']);
    $tagged->tags()->attach($tag);
    Query::factory()->for($user)->create(['name' => 'Autre rapport']);

    $this->actingAs($user)
        ->get(route('queries.index', ['search' => 'Paie Québec']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->count('queries.data', 1)
            ->where('queries.data.0.name', 'Rapport RH'));
});

test('the library payload exposes the category in the active locale with fallback', function () {
    $user = createConnectedUser(['locale' => 'es'], ['key' => 'client_x']);
    $translated = Category::factory()
        ->withTranslation('fr', 'Finance')
        ->withTranslation('es', 'Finanzas')
        ->create(['slug' => 'finance']);
    $fallbackOnly = Category::factory()
        ->withTranslation('fr', 'Ressources humaines')
        ->create(['slug' => 'rh']);
    Query::factory()->for($user)->create(['name' => 'A', 'category_id' => $translated->id]);
    Query::factory()->for($user)->create(['name' => 'B', 'category_id' => $fallbackOnly->id]);

    $tag = Tag::factory()->create(['slug' => 'mensuel', 'name' => 'Mensuel']);
    Query::query()->where('name', 'A')->sole()->tags()->attach($tag);

    $this->actingAs($user)
        ->get(route('queries.index', ['search' => '']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queries.data.1.category.name', 'Finanzas')
            ->where('queries.data.1.category.slug', 'finance')
            ->where('queries.data.1.tags.0.name', 'Mensuel')
            ->where('queries.data.0.category.name', 'Ressources humaines'));
});

test('category and tag filters never leak other users private queries', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $stranger = createConnectedUser();
    $category = Category::factory()->create(['slug' => 'finance']);
    $tag = Tag::factory()->create(['slug' => 'mensuel', 'name' => 'Mensuel']);

    $foreign = Query::factory()->for($stranger)->private()->create([
        'name' => 'Privée étrangère',
        'category_id' => $category->id,
    ]);
    $foreign->tags()->attach($tag);

    $this->actingAs($user)
        ->get(route('queries.index', ['category' => 'finance', 'tag' => 'mensuel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->count('queries.data', 0));
});

test('available categories are shared with the create form', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    Category::factory()->withTranslation('fr', 'Finance')->create(['slug' => 'finance']);
    Tag::factory()->withTranslation('fr', 'Mensuel')->create([
        'slug' => 'monthly',
        'name' => 'Mensuel',
    ]);

    $this->actingAs($user)
        ->get(route('queries.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->count('categories', 1)
            ->where('categories.0.slug', 'finance')
            ->where('categories.0.name', 'Finance')
            ->count('tags', 1)
            ->where('tags.0.slug', 'monthly')
            ->where('tags.0.label', 'Mensuel'));
});

test('official tags are displayed and searched in the active locale', function () {
    $user = createConnectedUser(['locale' => 'es'], ['key' => 'client_x']);
    $tag = Tag::factory()
        ->withTranslation('fr', 'Mensuel')
        ->withTranslation('es', 'Mensual')
        ->create(['slug' => 'monthly', 'name' => 'Mensuel']);
    $query = Query::factory()->for($user)->create(['name' => 'Rapport']);
    $query->tags()->attach($tag);

    $this->actingAs($user)
        ->get(route('queries.index', ['search' => 'Mensual']))
        ->assertInertia(fn (Assert $page) => $page
            ->count('queries.data', 1)
            ->where('queries.data.0.tags.0.name', 'Mensual'));
});
