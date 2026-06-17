<?php

use App\Models\Query;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can view the show page with tenant options', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('queries.show', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/show')
            ->where('query.id', $query->id)
            ->has('tenants')
            ->has('defaultTenant'));
});

test('a shared query is viewable by another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->shared()->create();

    $this->actingAs($other)
        ->get(route('queries.show', $query))
        ->assertOk();
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
