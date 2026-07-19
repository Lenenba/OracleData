<?php

use App\Enums\GroupRole;
use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use App\Models\AuditEvent;
use App\Models\Group;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\QueryUserShare;
use App\Models\User;

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

    expect(Query::find($query->id))->toBeNull()
        ->and(Query::withTrashed()->find($query->id))->not->toBeNull()
        ->and(Query::withTrashed()->find($query->id)?->deleted_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'query.archived')->count())->toBe(1);
});

test('archiving a query atomically revokes grants while preserving every share cycle', function () {
    $owner = createConnectedUser();
    $directRecipient = User::factory()->create();
    $groupMember = User::factory()->create();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($groupMember->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();
    $direct = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($directRecipient)
        ->permission(QuerySharePermission::EXECUTE)
        ->create();
    $historicalDirect = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for(User::factory())
        ->revoked()
        ->create();
    $groupShare = QueryGroupShare::factory()
        ->for($query, 'sharedQuery')
        ->for($group)
        ->for($owner, 'sharedByUser')
        ->permission(QuerySharePermission::CLONE)
        ->create();

    $this->actingAs($owner)
        ->delete(route('queries.destroy', $query))
        ->assertRedirect(route('queries.index'));

    expect(QueryUserShare::query()->count())->toBe(2)
        ->and(QueryGroupShare::query()->count())->toBe(1)
        ->and($direct->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($historicalDirect->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($groupShare->refresh()->status)->toBe(QueryGroupShare::STATUS_REVOKED)
        ->and(AuditEvent::query()->where('action', 'query.share_revoked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.group_share_revoked')->count())->toBe(1);

    $archived = AuditEvent::query()->where('action', 'query.archived')->sole();

    expect($archived->context)->toMatchArray([
        'access_level' => 'restricted',
        'revoked_share_count' => 2,
        'revoked_user_share_count' => 1,
        'revoked_group_share_count' => 1,
    ])
        ->and(json_encode($archived->context, JSON_THROW_ON_ERROR))
        ->not->toContain($query->name)
        ->not->toContain($group->name)
        ->not->toContain($directRecipient->email);

    Query::withTrashed()->findOrFail($query->id)->restore();

    expect($direct->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($groupShare->refresh()->status)->toBe(QueryGroupShare::STATUS_REVOKED);
});

test('a delegated sharing manager cannot archive the query', function () {
    $owner = User::factory()->create();
    $manager = createConnectedUser();
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($manager)
        ->permission(QuerySharePermission::MANAGE)
        ->create();

    $this->actingAs($manager)
        ->delete(route('queries.destroy', $query))
        ->assertForbidden();

    expect(Query::query()->find($query->id))->not->toBeNull();
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

// ─── updateAccessLevel ────────────────────────────────────────────────────────

test('guests cannot update access level', function () {
    $query = Query::factory()->create();

    $this->patch(route('queries.access-level', $query), ['access_level' => 'organization'])
        ->assertRedirect(route('login'));

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);
});

test('the owner can toggle a private query to shared', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->private()->create();

    $this->actingAs($user)
        ->patchJson(route('queries.access-level', $query), ['access_level' => 'organization'])
        ->assertOk()
        ->assertJsonPath('access_level', 'organization');

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::ORGANIZATION);
});

test('the owner can toggle a shared query to private', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->shared()->create();

    $this->actingAs($user)
        ->patchJson(route('queries.access-level', $query), ['access_level' => 'private'])
        ->assertOk()
        ->assertJsonPath('access_level', 'private');

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);
});

test('a user without manage permission cannot update access level', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($other)
        ->patchJson(route('queries.access-level', $query), ['access_level' => 'organization'])
        ->assertForbidden();

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);
});

test('access level must be private restricted or organization', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->patchJson(route('queries.access-level', $query), ['access_level' => 'public'])
        ->assertUnprocessable();

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);
});

test('inertia redirect variant works for non-JSON requests', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->private()->create();

    $this->actingAs($user)
        ->patch(route('queries.access-level', $query), ['access_level' => 'restricted'])
        ->assertRedirect();

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::RESTRICTED);
});
