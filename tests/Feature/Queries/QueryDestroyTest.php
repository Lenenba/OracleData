<?php

use App\Models\Query;
use App\Models\User;

beforeEach(function () {
    config()->set('fusion.default', 'client_x');
    config()->set('fusion.tenants', [
        'client_x' => [
            'label' => 'Client X',
            'base_url' => 'https://client-x.fa.oraclecloud.com',
            'username' => 'svc_x',
            'password' => 'secret_x',
        ],
    ]);
});

// ─── destroy ─────────────────────────────────────────────────────────────────

test('guests cannot delete a query', function () {
    $query = Query::factory()->create();

    $this->delete(route('queries.destroy', $query))
        ->assertRedirect(route('login'));

    expect(Query::count())->toBe(1);
});

test('the owner can delete their own query', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete(route('queries.destroy', $query))
        ->assertRedirect(route('queries.index'));

    expect(Query::find($query->id))->toBeNull();
});

test('a non-owner cannot delete a private query', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($other)
        ->delete(route('queries.destroy', $query))
        ->assertForbidden();

    expect(Query::find($query->id))->not->toBeNull();
});

test('a non-owner cannot delete a shared query', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->shared()->create();

    $this->actingAs($other)
        ->delete(route('queries.destroy', $query))
        ->assertForbidden();

    expect(Query::find($query->id))->not->toBeNull();
});

// ─── updateVisibility ─────────────────────────────────────────────────────────

test('guests cannot update visibility', function () {
    $query = Query::factory()->create();

    $this->patch(route('queries.visibility', $query), ['visibility' => 'shared'])
        ->assertRedirect(route('login'));

    expect($query->refresh()->visibility)->toBe('private');
});

test('the owner can toggle a private query to shared', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->private()->create();

    $this->actingAs($user)
        ->patchJson(route('queries.visibility', $query), ['visibility' => 'shared'])
        ->assertOk()
        ->assertJsonPath('visibility', 'shared');

    expect($query->refresh()->visibility)->toBe('shared');
});

test('the owner can toggle a shared query to private', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->shared()->create();

    $this->actingAs($user)
        ->patchJson(route('queries.visibility', $query), ['visibility' => 'private'])
        ->assertOk()
        ->assertJsonPath('visibility', 'private');

    expect($query->refresh()->visibility)->toBe('private');
});

test('a non-owner cannot update visibility', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($other)
        ->patchJson(route('queries.visibility', $query), ['visibility' => 'shared'])
        ->assertForbidden();

    expect($query->refresh()->visibility)->toBe('private');
});

test('visibility must be private or shared', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->patchJson(route('queries.visibility', $query), ['visibility' => 'public'])
        ->assertUnprocessable();

    expect($query->refresh()->visibility)->toBe('private');
});

test('inertia redirect variant works for non-JSON requests', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->private()->create();

    $this->actingAs($user)
        ->patch(route('queries.visibility', $query), ['visibility' => 'shared'])
        ->assertRedirect();

    expect($query->refresh()->visibility)->toBe('shared');
});
