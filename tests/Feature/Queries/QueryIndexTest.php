<?php

use App\Models\Query;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function () {
    $this->get(route('queries.index'))->assertRedirect(route('login'));
});

test('a user sees their own queries and shared ones, but not others private', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    Query::factory()->for($me)->private()->create(['name' => 'Mine']);
    Query::factory()->for($other)->shared()->create(['name' => 'Shared by other']);
    Query::factory()->for($other)->private()->create(['name' => 'Hidden']);

    $this->actingAs($me)
        ->get(route('queries.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/index')
            ->has('queries', 2)
            ->where('queries', fn (Collection $queries) => $queries
                ->pluck('name')
                ->doesntContain('Hidden')));
});

test('can.update is only true for queries the user owns', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    Query::factory()->for($me)->create(['name' => 'Mine']);
    Query::factory()->for($other)->shared()->create(['name' => 'Shared']);

    $this->actingAs($me)
        ->get(route('queries.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queries', fn (Collection $queries) => $queries
                ->every(fn (array $query) => $query['can']['update'] === ($query['owner'] === $me->name))));
});
