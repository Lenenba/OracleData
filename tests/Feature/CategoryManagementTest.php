<?php

use App\Models\Category;
use App\Models\Query;
use Inertia\Testing\AssertableInertia as Assert;

test('a super admin can view the category settings page', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    Category::factory()->withTranslation('fr', 'Finance')->create(['slug' => 'finance']);

    $this->actingAs($admin)
        ->get(route('categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/categories')
            ->count('categories', 1)
            ->where('categories.0.slug', 'finance')
            ->where('categories.0.translations.fr.name', 'Finance'));
});

test('a standard user cannot reach category management', function () {
    $user = createConnectedUser();

    $this->actingAs($user)->get(route('categories.index'))->assertForbidden();
    $this->actingAs($user)->post(route('categories.store'), [])->assertForbidden();
});

test('a super admin can create a category with its three translations', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);

    $this->actingAs($admin)->post(route('categories.store'), [
        'slug' => 'finance',
        'color' => '#0ea5e9',
        'translations' => [
            'fr' => ['name' => 'Finance'],
            'en' => ['name' => 'Finance'],
            'es' => ['name' => 'Finanzas'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(route('categories.index'));

    $category = Category::query()->sole();

    expect($category->slug)->toBe('finance')
        ->and($category->translations()->count())->toBe(3)
        ->and($category->nameFor('es'))->toBe('Finanzas');
});

test('the french translation is required', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);

    $this->actingAs($admin)->post(route('categories.store'), [
        'slug' => 'finance',
        'translations' => [
            'en' => ['name' => 'Finance'],
        ],
    ])->assertInvalid(['translations.fr.name']);
});

test('a super admin can update a category and its translations', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    $category = Category::factory()->withTranslation('fr', 'Ancien nom')->create();

    $this->actingAs($admin)->put(route('categories.update', $category), [
        'slug' => 'nouveau-slug',
        'color' => '#f97316',
        'translations' => [
            'fr' => ['name' => 'Nouveau nom'],
            'es' => ['name' => 'Nuevo nombre'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(route('categories.index'));

    $category->refresh();

    expect($category->slug)->toBe('nouveau-slug')
        ->and($category->nameFor('fr'))->toBe('Nouveau nom')
        ->and($category->nameFor('es'))->toBe('Nuevo nombre');
});

test('deleting a category detaches it from queries without deleting them', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    $category = Category::factory()->create();
    $query = Query::factory()->for($admin)->create(['category_id' => $category->id]);

    $this->actingAs($admin)
        ->delete(route('categories.destroy', $category))
        ->assertRedirect(route('categories.index'));

    expect(Category::query()->count())->toBe(0)
        ->and($query->refresh()->category_id)->toBeNull();
});

test('a duplicate slug is rejected', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    Category::factory()->create(['slug' => 'finance']);

    $this->actingAs($admin)->post(route('categories.store'), [
        'slug' => 'finance',
        'translations' => ['fr' => ['name' => 'Finance']],
    ])->assertInvalid(['slug']);
});
