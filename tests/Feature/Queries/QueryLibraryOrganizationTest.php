<?php

use App\Models\Query;
use App\Models\QueryUserPreference;
use App\Models\SavedQueryView;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('favorite and pinned filters are personal and pinned queries are listed first', function () {
    $user = createConnectedUser();
    $other = User::factory()->create();
    $regular = Query::factory()->for($user)->create([
        'name' => 'Récente',
        'updated_at' => now(),
    ]);
    $pinned = Query::factory()->for($other)->shared()->create([
        'name' => 'Épinglée',
        'updated_at' => now()->subDay(),
    ]);
    QueryUserPreference::query()->create([
        'user_id' => $user->id,
        'query_id' => $pinned->id,
        'is_favorite' => true,
        'is_pinned' => true,
        'pinned_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('queries.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queries.data.0.id', $pinned->id)
            ->where('queries.data.0.preference.is_pinned', true)
            ->where('summary.favorites', 1)
            ->where('summary.pinned', 1));

    $this->actingAs($user)
        ->get(route('queries.index', ['favorite' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('favorite', true)
            ->count('queries.data', 1)
            ->where('queries.data.0.id', $pinned->id));

    $this->actingAs($other)
        ->get(route('queries.index', ['pinned' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pinned', true)
            ->count('queries.data', 0));

    expect($regular->exists)->toBeTrue();
});

test('the library exposes usage statistics and supports usage sorting', function () {
    $user = createConnectedUser();
    $lessUsed = Query::factory()->for($user)->create([
        'name' => 'Peu utilisée',
        'execution_count' => 2,
        'successful_execution_count' => 1,
        'last_executed_at' => now()->subDay(),
    ]);
    $mostUsed = Query::factory()->for($user)->create([
        'name' => 'Très utilisée',
        'execution_count' => 10,
        'successful_execution_count' => 9,
        'last_executed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('queries.index', ['sort' => 'executions_desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', 'executions_desc')
            ->where('queries.data.0.id', $mostUsed->id)
            ->where('queries.data.0.statistics.execution_count', 10)
            ->where('queries.data.0.statistics.success_rate', 90)
            ->where('usage.total_executions', 12)
            ->where('usage.success_rate', 83.3));

    expect($lessUsed->exists)->toBeTrue();
});

test('saved views are shared with the library and a default view is applied', function () {
    $user = createConnectedUser();
    Query::factory()->for($user)->create(['name' => 'Alpha']);
    Query::factory()->for($user)->create(['name' => 'Zulu']);
    SavedQueryView::query()->create([
        'user_id' => $user->id,
        'name' => 'Noms inversés',
        'filters' => ['scope' => 'mine', 'sort' => 'name_desc'],
        'is_default' => true,
    ]);

    $this->actingAs($user)
        ->get(route('queries.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'mine')
            ->where('sort', 'name_desc')
            ->where('queries.data.0.name', 'Zulu')
            ->count('savedViews', 1)
            ->where('savedViews.0.name', 'Noms inversés')
            ->where('savedViews.0.is_default', true));
});

test('view none explicitly bypasses the default saved view', function () {
    $user = createConnectedUser();
    $other = User::factory()->create();
    Query::factory()->for($user)->create(['name' => 'Personnelle']);
    Query::factory()->for($other)->shared()->create(['name' => 'Partagée']);
    SavedQueryView::query()->create([
        'user_id' => $user->id,
        'name' => 'Mes requêtes',
        'filters' => ['scope' => 'mine'],
        'is_default' => true,
    ]);

    $this->actingAs($user)
        ->get(route('queries.index', ['view' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'all')
            ->count('queries.data', 2));
});

test('tag suggestions include only official or accessible tags and are capped', function () {
    $user = createConnectedUser();
    $other = User::factory()->create();
    $ownTag = Tag::factory()->create(['name' => 'Personnel', 'slug' => 'personnel']);
    $sharedTag = Tag::factory()->create(['name' => 'Partagé', 'slug' => 'partage']);
    $privateTag = Tag::factory()->create(['name' => 'Confidentiel', 'slug' => 'confidentiel']);
    $officialTag = Tag::factory()->withTranslation('fr', 'Officiel')->create([
        'name' => 'Officiel',
        'slug' => 'officiel',
    ]);
    $ownQuery = Query::factory()->for($user)->create();
    $sharedQuery = Query::factory()->for($other)->shared()->create();
    $privateQuery = Query::factory()->for($other)->private()->create();
    $ownQuery->tags()->attach($ownTag);
    $sharedQuery->tags()->attach($sharedTag);
    $privateQuery->tags()->attach($privateTag);

    Tag::factory()
        ->count(101)
        ->sequence(fn ($sequence) => [
            'name' => sprintf('Officiel %03d', $sequence->index),
            'slug' => sprintf('officiel-%03d', $sequence->index),
        ])
        ->withTranslation('fr')
        ->create();

    $this->actingAs($user)
        ->get(route('queries.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('tags', 100)
            ->where('tags', fn (Collection $tags) => $tags
                ->pluck('slug')
                ->contains($privateTag->slug) === false));

    expect($officialTag->exists)->toBeTrue();
});

test('library search matches the owner name without exposing private queries', function () {
    $reader = createConnectedUser();
    $owner = User::factory()->create(['name' => 'Alice Finance']);
    Query::factory()->for($owner)->shared()->create(['name' => 'Visible']);
    Query::factory()->for($owner)->private()->create(['name' => 'Cachée']);

    $this->actingAs($reader)
        ->get(route('queries.index', ['search' => 'Alice Finance']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queries.data', fn (Collection $queries) => $queries
                ->pluck('name')
                ->all() === ['Visible']));
});
