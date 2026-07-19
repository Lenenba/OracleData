<?php

use App\Enums\GroupRole;
use App\Enums\QuerySharePermission;
use App\Models\AuditEvent;
use App\Models\Group;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

test('the groups page has a stable empty state', function () {
    $user = createConnectedUser();

    $this->actingAs($user)
        ->get(route('groups.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('groups/index')
            ->has('groups', 0)
            ->where('selectedGroup', null)
            ->has('members.data', 0)
            ->has('candidates.data', 0));
});

test('a user can create a group and becomes its immutable owner member', function () {
    $owner = createConnectedUser();

    $this->actingAs($owner)
        ->postJson(route('groups.store'), [
            'name' => 'Équipe finances',
            'description' => 'Analystes des comptes fournisseurs.',
        ])
        ->assertCreated()
        ->assertJsonPath('group.name', 'Équipe finances')
        ->assertJsonPath('group.current_user_role', 'owner')
        ->assertJsonPath('group.member_count', 1);

    $group = Group::query()->sole();

    expect($group->owner_id)->toBe($owner->id)
        ->and($group->members()->whereKey($owner->id)->value('group_user.role'))
        ->toBe(GroupRole::OWNER->value)
        ->and(AuditEvent::query()->where('action', 'group.created')->count())->toBe(1);

    $this->actingAs($owner)
        ->get(route('groups.index', ['group' => $group->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('groups/index')
            ->has('groups', 1)
            ->where('selectedGroup.id', $group->id)
            ->where('members.data.0.role', 'owner'));
});

test('group names cannot contain only whitespace', function () {
    $owner = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->postJson(route('groups.store'), ['name' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
    $this->actingAs($owner)
        ->patchJson(route('groups.update', $group), ['name' => "\t "])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Group::query()->count())->toBe(1);
});

test('owners and managers can administer only the membership roles within their authority', function () {
    $owner = createConnectedUser();
    $manager = createConnectedUser();
    $member = User::factory()->create();
    $newMember = User::factory()->create();
    $otherManager = User::factory()->create();
    $group = Group::factory()->for($owner, 'owner')->create();
    $group->members()->attach($manager->id, ['role' => GroupRole::MANAGER->value]);
    $group->members()->attach($member->id, ['role' => GroupRole::MEMBER->value]);
    $group->members()->attach($otherManager->id, ['role' => GroupRole::MANAGER->value]);

    $this->actingAs($manager)
        ->postJson(route('groups.members.store', $group), [
            'user_id' => $newMember->id,
            'role' => 'member',
        ])
        ->assertCreated();
    $this->actingAs($manager)
        ->postJson(route('groups.members.store', $group), [
            'user_id' => User::factory()->create()->id,
            'role' => 'manager',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
    $this->actingAs($manager)
        ->deleteJson(route('groups.members.destroy', [$group, $otherManager]))
        ->assertForbidden();
    $this->actingAs($manager)
        ->deleteJson(route('groups.members.destroy', [$group, $manager]))
        ->assertForbidden();
    $this->actingAs($manager)
        ->deleteJson(route('groups.members.destroy', [$group, $member]))
        ->assertNoContent();

    $this->actingAs($owner)
        ->patchJson(route('groups.members.update', [$group, $newMember]), [
            'role' => 'manager',
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->deleteJson(route('groups.members.destroy', [$group, $otherManager]))
        ->assertNoContent();
    $this->actingAs($owner)
        ->deleteJson(route('groups.members.destroy', [$group, $owner]))
        ->assertForbidden();

    expect($group->members()->whereKey($member->id)->exists())->toBeFalse()
        ->and($group->members()->whereKey($newMember->id)->value('group_user.role'))
        ->toBe(GroupRole::MANAGER->value)
        ->and(AuditEvent::query()->where('action', 'group.member_added')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'group.member_role_updated')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'group.member_removed')->count())->toBe(2);
});

test('group routes enforce membership and ownership without exposing unrelated groups', function () {
    $owner = createConnectedUser();
    $outsider = createConnectedUser();
    $candidate = User::factory()->create();
    $group = Group::factory()->for($owner, 'owner')->create();

    $this->actingAs($outsider)
        ->get(route('groups.index', ['group' => $group->id]))
        ->assertNotFound();
    $this->actingAs($outsider)
        ->patchJson(route('groups.update', $group), [
            'name' => 'Intrusion',
        ])
        ->assertForbidden();
    $this->actingAs($outsider)
        ->deleteJson(route('groups.destroy', $group))
        ->assertForbidden();
    $this->actingAs($outsider)
        ->postJson(route('groups.members.store', $group), [
            'user_id' => $candidate->id,
            'role' => 'member',
        ])
        ->assertForbidden();

    expect($group->refresh()->name)->not->toBe('Intrusion')
        ->and($group->members()->whereKey($candidate->id)->exists())->toBeFalse();
});

test('archiving a group revokes its query grants and preserves their history', function () {
    $owner = createConnectedUser();
    $member = createConnectedUser();
    $group = Group::factory()->for($owner, 'owner')->create(['name' => 'Contrôle interne']);
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
        ->deleteJson(route('groups.destroy', $group))
        ->assertNoContent();

    expect(Group::query()->find($group->id))->toBeNull()
        ->and(Group::withTrashed()->find($group->id))->not->toBeNull()
        ->and($share->refresh()->status)->toBe(QueryGroupShare::STATUS_REVOKED)
        ->and($share->group_name)->toBe('Contrôle interne')
        ->and(Gate::forUser($member)->allows('view', $query->refresh()))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'group.archived')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.group_share_revoked')->count())->toBe(1);
});
