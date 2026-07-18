<?php

use App\Models\Query;
use App\Models\QueryUserPreference;
use App\Models\User;

function preferenceUrl(Query $query): string
{
    return route('queries.preference', $query);
}

test('guests cannot mutate query preferences through the production route', function () {
    $query = Query::factory()->create();

    $this->patchJson(preferenceUrl($query), ['is_favorite' => true])
        ->assertUnauthorized();

    expect(QueryUserPreference::query()->count())->toBe(0);
});

test('users without onboarding cannot mutate query preferences', function () {
    $user = User::factory()->withoutOnboarding()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), ['is_favorite' => true])
        ->assertRedirect(route('onboarding.connection'));

    expect(QueryUserPreference::query()->count())->toBe(0);
});

test('an owner can mark a query as favorite', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->private()->create();

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), [
            'is_favorite' => true,
            'is_pinned' => false,
        ])
        ->assertOk()
        ->assertExactJson([
            'query_id' => $query->id,
            'is_favorite' => true,
            'is_pinned' => false,
            'pinned_at' => null,
        ]);

    $this->assertDatabaseHas('query_user_preferences', [
        'user_id' => $user->id,
        'query_id' => $query->id,
        'is_favorite' => true,
        'is_pinned' => false,
        'pinned_at' => null,
    ]);
});

test('a reader can keep an independent preference for a shared query', function () {
    $author = User::factory()->create();
    $reader = User::factory()->create();
    $query = Query::factory()->for($author)->shared()->create();

    $this->actingAs($reader)
        ->patchJson(preferenceUrl($query), ['is_favorite' => true])
        ->assertOk();

    expect(QueryUserPreference::query()->sole()->user_id)->toBe($reader->id)
        ->and(QueryUserPreference::query()->sole()->query_id)->toBe($query->id);
});

test('a user cannot set a preference on another users private query', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($stranger)
        ->patchJson(preferenceUrl($query), ['is_favorite' => true])
        ->assertForbidden();

    expect(QueryUserPreference::query()->count())->toBe(0);
});

test('partial updates reuse one row and preserve the other preference', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), ['is_favorite' => true])
        ->assertOk();

    $this->travel(1)->minute();

    $response = $this->actingAs($user)
        ->patchJson(preferenceUrl($query), ['is_pinned' => true])
        ->assertOk()
        ->assertJson([
            'query_id' => $query->id,
            'is_favorite' => true,
            'is_pinned' => true,
        ]);

    expect(QueryUserPreference::query()->count())->toBe(1)
        ->and(QueryUserPreference::query()->sole()->pinned_at)->not->toBeNull()
        ->and($response->json('pinned_at'))->not->toBeNull();
});

test('unpinning clears pinned_at while preserving a favorite', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();
    QueryUserPreference::query()->create([
        'user_id' => $user->id,
        'query_id' => $query->id,
        'is_favorite' => true,
        'is_pinned' => true,
        'pinned_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), ['is_pinned' => false])
        ->assertOk()
        ->assertJson([
            'is_favorite' => true,
            'is_pinned' => false,
            'pinned_at' => null,
        ]);

    $preference = QueryUserPreference::query()->sole();

    expect($preference->is_favorite)->toBeTrue()
        ->and($preference->is_pinned)->toBeFalse()
        ->and($preference->pinned_at)->toBeNull();
});

test('clearing both values deletes the empty preference row', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();
    QueryUserPreference::query()->create([
        'user_id' => $user->id,
        'query_id' => $query->id,
        'is_favorite' => true,
        'is_pinned' => true,
        'pinned_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), [
            'is_favorite' => false,
            'is_pinned' => false,
        ])
        ->assertOk()
        ->assertExactJson([
            'query_id' => $query->id,
            'is_favorite' => false,
            'is_pinned' => false,
            'pinned_at' => null,
        ]);

    expect(QueryUserPreference::query()->count())->toBe(0);
});

test('preference values must be boolean and at least one must be present', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), ['is_favorite' => 'yes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_favorite');

    $this->actingAs($user)
        ->patchJson(preferenceUrl($query), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_favorite', 'is_pinned']);
});

test('preferences cascade when their query or user is deleted', function () {
    $author = User::factory()->create();
    $reader = User::factory()->create();
    $firstQuery = Query::factory()->for($author)->shared()->create();
    $secondQuery = Query::factory()->for($author)->shared()->create();

    foreach ([$firstQuery, $secondQuery] as $query) {
        QueryUserPreference::query()->create([
            'user_id' => $reader->id,
            'query_id' => $query->id,
            'is_favorite' => true,
        ]);
    }

    $firstQuery->delete();

    expect(QueryUserPreference::query()->count())->toBe(1);

    $reader->delete();

    expect(QueryUserPreference::query()->count())->toBe(0)
        ->and($secondQuery->fresh())->not->toBeNull();
});
