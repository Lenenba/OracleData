<?php

use App\Models\Query;
use App\Models\Tag;
use App\Models\TagTranslation;
use Inertia\Testing\AssertableInertia as Assert;

test('a super admin can create an official tag with three translations', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);

    $this->actingAs($admin)->post(route('tags.store'), [
        'slug' => 'monthly',
        'translations' => [
            'fr' => ['name' => 'Mensuel'],
            'en' => ['name' => 'Monthly'],
            'es' => ['name' => 'Mensual'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(route('categories.index'));

    $tag = Tag::query()->sole();

    expect($tag->name)->toBe('Mensuel')
        ->and($tag->translations()->count())->toBe(3)
        ->and($tag->nameFor('es'))->toBe('Mensual');
});

test('a standard user cannot manage official tags', function () {
    $user = createConnectedUser();

    $this->actingAs($user)->post(route('tags.store'), [])->assertForbidden();
});

test('a super admin can translate an existing free form tag', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    $tag = Tag::factory()->create(['slug' => 'monthly', 'name' => 'Mensuel']);

    $this->actingAs($admin)->put(route('tags.update', $tag), [
        'slug' => 'monthly',
        'translations' => [
            'fr' => ['name' => 'Mensuel'],
            'en' => ['name' => 'Monthly'],
        ],
    ])->assertSessionHasNoErrors();

    expect($tag->refresh()->nameFor('en'))->toBe('Monthly')
        ->and($tag->nameFor('es'))->toBe('Mensuel');
});

test('deleting a tag only removes its query associations', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    $tag = Tag::factory()->withTranslation('fr', 'Mensuel')->create();
    $query = Query::factory()->for($admin)->create();
    $query->tags()->attach($tag);

    $this->actingAs($admin)
        ->delete(route('tags.destroy', $tag))
        ->assertRedirect(route('categories.index'));

    expect($query->fresh())->not->toBeNull()
        ->and($query->tags()->count())->toBe(0)
        ->and(TagTranslation::query()->count())->toBe(0);
});

test('the taxonomy settings payload includes official tag translations', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);
    Tag::factory()->withTranslation('fr', 'Mensuel')->create(['slug' => 'monthly']);

    $this->actingAs($admin)
        ->get(route('categories.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('tags', 1)
            ->where('tags.0.slug', 'monthly')
            ->where('tags.0.translations.fr.name', 'Mensuel'));
});

test('unsupported translation locales are rejected', function () {
    $admin = createConnectedUser(['is_super_admin' => true]);

    $this->actingAs($admin)->post(route('tags.store'), [
        'slug' => 'monthly',
        'translations' => [
            'fr' => ['name' => 'Mensuel'],
            'de' => ['name' => 'Monatlich'],
        ],
    ])->assertInvalid(['translations']);
});

test('a tag name must produce a non empty slug', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);

    $this->actingAs($user)->post(route('queries.store'), [
        'name' => 'Requête étiquetée',
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'access_level' => 'private',
        'tags' => ['🔥'],
    ])->assertInvalid(['tags.0']);

    expect(fn () => Tag::findOrCreateByName('🔥'))
        ->toThrow(InvalidArgumentException::class)
        ->and(Tag::query()->count())->toBe(0);
});
