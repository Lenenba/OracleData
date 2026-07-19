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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

test('the first group share restricts a private query and grants every current member access', function () {
    $owner = createConnectedUser();
    $member = createConnectedUser();
    $outsider = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create(['name' => 'Comptes fournisseurs']);
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->getJson(route('queries.shares.index', [
            'query' => $query,
            'recipient_type' => 'group',
        ]))
        ->assertOk()
        ->assertJsonPath('recipientType', 'group')
        ->assertJsonPath('candidates.data.0.id', $group->id)
        ->assertJsonPath('candidates.data.0.recipient_type', 'group')
        ->assertJsonCount(3, 'groupPermissions');

    $this->actingAs($owner)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'execute',
        ])
        ->assertCreated()
        ->assertJsonPath('share.recipient_type', 'group')
        ->assertJsonPath('share.permission', 'execute');

    $share = QueryGroupShare::query()->sole();

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::RESTRICTED)
        ->and($share->group_name)->toBe('Comptes fournisseurs')
        ->and(Gate::forUser($member)->allows('view', $query))->toBeTrue()
        ->and(Gate::forUser($member)->allows('execute', $query))->toBeTrue()
        ->and(Gate::forUser($member)->allows('clone', $query))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $query))->toBeFalse()
        ->and(Query::query()->accessibleTo($member)->whereKey($query)->exists())->toBeTrue()
        ->and(Query::query()->sharedWith($member)->whereKey($query)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'query.group_shared')->count())->toBe(1);
});

test('group permissions remain cumulative and never include sharing management', function (QuerySharePermission $permission) {
    $owner = User::factory()->create();
    $member = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryGroupShare::factory()
        ->for($query, 'sharedQuery')
        ->for($group)
        ->for($owner, 'sharedByUser')
        ->permission($permission)
        ->create();

    expect(Gate::forUser($member)->allows('view', $query))->toBeTrue()
        ->and(Gate::forUser($member)->allows('execute', $query))
        ->toBe($permission->implies(QuerySharePermission::EXECUTE))
        ->and(Gate::forUser($member)->allows('clone', $query))
        ->toBe($permission->implies(QuerySharePermission::CLONE))
        ->and(Gate::forUser($member)->allows('manageSharing', $query))->toBeFalse();
})->with([
    QuerySharePermission::VIEW,
    QuerySharePermission::EXECUTE,
    QuerySharePermission::CLONE,
]);

test('group permissions are enforced by the query HTTP endpoints', function () {
    $owner = User::factory()->create();
    $viewer = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($viewer->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryGroupShare::factory()
        ->for($query, 'sharedQuery')
        ->for($group)
        ->for($owner, 'sharedByUser')
        ->permission(QuerySharePermission::VIEW)
        ->create();

    $this->actingAs($viewer)
        ->get(route('queries.show', $query))
        ->assertOk();
    $this->actingAs($viewer)
        ->postJson(route('queries.run', $query))
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post(route('queries.clone', $query))
        ->assertForbidden();
    $this->actingAs($viewer)
        ->getJson(route('queries.shares.index', $query))
        ->assertForbidden();
});

test('membership removal immediately removes inherited access without changing the group grant', function () {
    $owner = User::factory()->create();
    $member = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();
    $share = QueryGroupShare::factory()
        ->for($query, 'sharedQuery')
        ->for($group)
        ->for($owner, 'sharedByUser')
        ->permission(QuerySharePermission::CLONE)
        ->create();

    expect(Gate::forUser($member)->allows('clone', $query))->toBeTrue();

    $this->actingAs($owner)
        ->deleteJson(route('groups.members.destroy', [$group, $member]))
        ->assertNoContent();

    expect(Gate::forUser($member)->allows('view', $query->refresh()))->toBeFalse()
        ->and(Query::query()->accessibleTo($member)->whereKey($query)->exists())->toBeFalse()
        ->and($share->refresh()->status)->toBe(QueryGroupShare::STATUS_ACCEPTED);
});

test('expired revoked and reshared group grants preserve every lifecycle', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-18 12:00:00'));
    $owner = createConnectedUser();
    $member = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'view',
            'expires_at' => now()->addHour()->toISOString(),
        ])
        ->assertCreated();
    $first = QueryGroupShare::query()->sole();

    $this->actingAs($owner)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'execute',
        ])
        ->assertConflict();
    $this->actingAs($owner)
        ->deleteJson(route('queries.group-shares.destroy', [$query, $first]))
        ->assertNoContent();

    $group->update(['name' => 'Groupe renommé']);

    $this->actingAs($owner)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'execute',
        ])
        ->assertCreated();

    $second = QueryGroupShare::query()->whereKeyNot($first->id)->sole();

    expect(QueryGroupShare::query()->count())->toBe(2)
        ->and($first->refresh()->status)->toBe(QueryGroupShare::STATUS_REVOKED)
        ->and($first->group_name)->not->toBe('Groupe renommé')
        ->and($second->group_name)->toBe('Groupe renommé')
        ->and($second->permission)->toBe(QuerySharePermission::EXECUTE);

    $this->actingAs($owner)
        ->getJson(route('queries.shares.index', [
            'query' => $query,
            'history_recipient_type' => 'group',
        ]))
        ->assertOk()
        ->assertJsonPath('activeShares.0.id', $second->id)
        ->assertJsonPath('activeShares.0.recipient_type', 'group')
        ->assertJsonPath('shareHistory.data.0.id', $first->id);
});

test('the strongest direct organization or group permission wins', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($member)
        ->permission(QuerySharePermission::VIEW)
        ->create();
    QueryGroupShare::factory()
        ->for($query, 'sharedQuery')
        ->for($group)
        ->for($owner, 'sharedByUser')
        ->permission(QuerySharePermission::CLONE)
        ->create();

    expect($query->permissionFor($member))->toBe(QuerySharePermission::CLONE);

    $query->forceFill(['access_level' => QueryAccessLevel::ORGANIZATION])->save();
    expect($query->refresh()->permissionFor($member))->toBe(QuerySharePermission::CLONE);
});

test('a delegated manager cannot grant manage or exceed their expiration through a group', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-18 12:00:00'));
    $owner = User::factory()->create();
    $manager = createConnectedUser();
    $group = Group::factory()->for($manager, 'owner')->create();
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($manager)
        ->permission(QuerySharePermission::MANAGE)
        ->create(['expires_at' => now()->addHour()]);

    $this->actingAs($manager)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'manage',
            'expires_at' => now()->addMinutes(30)->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permission');
    $this->actingAs($manager)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'clone',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');
    $this->actingAs($manager)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'clone',
            'expires_at' => now()->addHours(2)->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');
    $this->actingAs($manager)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'clone',
            'expires_at' => now()->addMinutes(30)->toISOString(),
        ])
        ->assertCreated();
});

test('switching to private revokes active user and group grants together', function () {
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
        ->create();
    $groupShare = QueryGroupShare::factory()
        ->for($query, 'sharedQuery')
        ->for($group)
        ->for($owner, 'sharedByUser')
        ->create();

    $this->actingAs($owner)
        ->patchJson(route('queries.access-level', $query), [
            'access_level' => 'private',
        ])
        ->assertOk();

    expect($direct->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($groupShare->refresh()->status)->toBe(QueryGroupShare::STATUS_REVOKED)
        ->and($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);

    $event = AuditEvent::query()->where('action', 'query.access_level_updated')->sole();

    expect($event->context)->toMatchArray([
        'revoked_share_count' => 2,
        'revoked_user_share_count' => 1,
        'revoked_group_share_count' => 1,
    ]);
});

test('group share endpoints reject unrelated groups and nested IDOR attempts', function () {
    $owner = createConnectedUser();
    $otherOwner = createConnectedUser();
    $group = Group::factory()->for($otherOwner, 'owner')->create();
    $query = Query::factory()->for($owner)->restricted()->create();
    $otherQuery = Query::factory()->for($otherOwner)->restricted()->create();
    $otherShare = QueryGroupShare::factory()
        ->for($otherQuery, 'sharedQuery')
        ->for($group)
        ->for($otherOwner, 'sharedByUser')
        ->create();

    $this->actingAs($owner)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'view',
        ])
        ->assertNotFound();
    $this->actingAs($owner)
        ->patchJson(route('queries.group-shares.update', [$query, $otherShare]), [
            'permission' => 'clone',
        ])
        ->assertNotFound();
    $this->actingAs($owner)
        ->deleteJson(route('queries.group-shares.destroy', [$query, $otherShare]))
        ->assertNotFound();
});

test('group share audit events contain IDs but no group names or member emails', function () {
    $owner = createConnectedUser();
    $member = User::factory()->create(['email' => 'secret-group-member@example.test']);
    $group = Group::factory()->for($owner, 'owner')->create(['name' => 'Sensitive Group Name']);
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $query = Query::factory()->for($owner)->restricted()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.group-shares.store', $query), [
            'group_id' => $group->id,
            'permission' => 'view',
        ])
        ->assertCreated();
    $share = QueryGroupShare::query()->sole();
    $this->actingAs($owner)
        ->patchJson(route('queries.group-shares.update', [$query, $share]), [
            'permission' => 'clone',
            'expires_at' => now()->addDay()->toISOString(),
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->deleteJson(route('queries.group-shares.destroy', [$query, $share]))
        ->assertNoContent();

    $contexts = json_encode(
        AuditEvent::query()
            ->where('subject_type', $query->getMorphClass())
            ->where('subject_id', $query->id)
            ->pluck('context')
            ->all(),
        JSON_THROW_ON_ERROR,
    );

    expect($contexts)
        ->not->toContain('Sensitive Group Name')
        ->not->toContain('secret-group-member@example.test');
});
