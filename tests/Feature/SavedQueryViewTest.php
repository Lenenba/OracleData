<?php

use App\Models\SavedQueryView;
use App\Models\User;

test('guests cannot mutate saved query views through the production routes', function () {
    $view = SavedQueryView::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Privée',
        'filters' => [],
    ]);

    $this->postJson(route('saved-query-views.store'), ['name' => 'Nouvelle', 'filters' => []])
        ->assertUnauthorized();
    $this->putJson(route('saved-query-views.update', $view), ['name' => 'Volée', 'filters' => []])
        ->assertUnauthorized();
    $this->deleteJson(route('saved-query-views.destroy', $view))
        ->assertUnauthorized();
});

test('users without onboarding cannot mutate saved query views', function () {
    $user = User::factory()->withoutOnboarding()->create();

    $this->actingAs($user)
        ->postJson(route('saved-query-views.store'), ['name' => 'Nouvelle', 'filters' => []])
        ->assertRedirect(route('onboarding.connection'));

    expect(SavedQueryView::query()->count())->toBe(0);
});

test('an authenticated user can store a whitelisted saved view as json', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('saved-query-views.store'), [
            'name' => 'Mes rapports favoris',
            'filters' => [
                'scope' => 'mine',
                'search' => 'rapport',
                'category' => 'finance',
                'tag' => 'mensuel',
                'sort' => 'executions_desc',
                'favorite' => true,
                'pinned' => false,
            ],
            'is_default' => true,
            'user_id' => User::factory()->create()->id,
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Mes rapports favoris')
        ->assertJsonPath('filters.scope', 'mine')
        ->assertJsonPath('filters.favorite', true)
        ->assertJsonPath('is_default', true);

    $view = SavedQueryView::query()->sole();

    expect($view->user_id)->toBe($user->id)
        ->and($view->filters)->toBe([
            'scope' => 'mine',
            'search' => 'rapport',
            'category' => 'finance',
            'tag' => 'mensuel',
            'sort' => 'executions_desc',
            'favorite' => true,
            'pinned' => false,
        ])
        ->and($view->is_default)->toBeTrue();
});

test('unknown or invalid filters are rejected', function (array $filters, string $error) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('saved-query-views.store'), [
            'name' => 'Vue invalide',
            'filters' => $filters,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'unknown key' => [['scope' => 'mine', 'tenant' => 'secret'], 'filters'],
    'invalid scope' => [['scope' => 'organization'], 'filters.scope'],
    'invalid sort' => [['sort' => 'random'], 'filters.sort'],
    'favorite must be boolean' => [['favorite' => 'yes'], 'filters.favorite'],
    'pinned must be boolean' => [['pinned' => 2], 'filters.pinned'],
]);

test('view names are unique per user but reusable by another user', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    SavedQueryView::query()->create([
        'user_id' => $first->id,
        'name' => 'Finance',
        'filters' => [],
    ]);

    $this->actingAs($first)
        ->postJson(route('saved-query-views.store'), ['name' => 'Finance', 'filters' => []])
        ->assertJsonValidationErrors('name');

    $this->actingAs($second)
        ->postJson(route('saved-query-views.store'), ['name' => 'Finance', 'filters' => []])
        ->assertCreated();

    expect(SavedQueryView::query()->where('name', 'Finance')->count())->toBe(2);
});

test('setting a default clears only the same users previous default', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $previous = SavedQueryView::query()->create([
        'user_id' => $user->id,
        'name' => 'Ancienne',
        'filters' => [],
        'is_default' => true,
    ]);
    $otherDefault = SavedQueryView::query()->create([
        'user_id' => $other->id,
        'name' => 'Autre utilisateur',
        'filters' => [],
        'is_default' => true,
    ]);

    $this->actingAs($user)
        ->postJson(route('saved-query-views.store'), [
            'name' => 'Nouvelle',
            'filters' => ['scope' => 'shared'],
            'is_default' => true,
        ])
        ->assertCreated();

    expect($previous->refresh()->is_default)->toBeFalse()
        ->and($otherDefault->refresh()->is_default)->toBeTrue()
        ->and(SavedQueryView::query()->where('user_id', $user->id)->where('is_default', true)->count())->toBe(1);
});

test('an owner can update a saved view and make it the default', function () {
    $user = User::factory()->create();
    $previous = SavedQueryView::query()->create([
        'user_id' => $user->id,
        'name' => 'Précédente',
        'filters' => [],
        'is_default' => true,
    ]);
    $view = SavedQueryView::query()->create([
        'user_id' => $user->id,
        'name' => 'À modifier',
        'filters' => ['scope' => 'all'],
    ]);

    $this->actingAs($user)
        ->putJson(route('saved-query-views.update', $view), [
            'name' => 'Mes épinglées',
            'filters' => ['scope' => 'mine', 'pinned' => true, 'search' => ''],
            'is_default' => true,
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Mes épinglées')
        ->assertJsonPath('filters.scope', 'mine')
        ->assertJsonPath('filters.pinned', true)
        ->assertJsonMissingPath('filters.search')
        ->assertJsonPath('is_default', true);

    expect($view->refresh()->filters)->toBe(['scope' => 'mine', 'pinned' => true])
        ->and($view->is_default)->toBeTrue()
        ->and($previous->refresh()->is_default)->toBeFalse();
});

test('a user cannot update or delete another users saved view', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $view = SavedQueryView::query()->create([
        'user_id' => $owner->id,
        'name' => 'Privée',
        'filters' => [],
    ]);

    $this->actingAs($attacker)
        ->putJson(route('saved-query-views.update', $view), [
            'name' => 'Volée',
            'filters' => [],
        ])
        ->assertNotFound();

    $this->actingAs($attacker)
        ->deleteJson(route('saved-query-views.destroy', $view))
        ->assertNotFound();

    expect($view->refresh()->name)->toBe('Privée');
});

test('an owner can delete a saved view as json', function () {
    $user = User::factory()->create();
    $view = SavedQueryView::query()->create([
        'user_id' => $user->id,
        'name' => 'Temporaire',
        'filters' => [],
    ]);

    $this->actingAs($user)
        ->deleteJson(route('saved-query-views.destroy', $view))
        ->assertNoContent();

    expect(SavedQueryView::query()->whereKey($view->id)->exists())->toBeFalse();
});

test('browser form requests redirect back after a mutation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/queries')
        ->post(route('saved-query-views.store'), [
            'name' => 'Vue navigateur',
            'filters' => ['sort' => 'name_asc'],
        ])
        ->assertRedirect('/queries')
        ->assertSessionHas('status', 'saved-query-view-created');
});
