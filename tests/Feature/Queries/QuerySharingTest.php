<?php

use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can search paginated candidates and inspect active and historical shares', function () {
    $owner = createConnectedUser();
    $query = Query::factory()->for($owner)->restricted()->create(['name' => 'Factures fournisseurs']);
    $activeRecipient = User::factory()->create([
        'name' => 'Recipient Active',
        'email' => 'active@example.test',
    ]);
    $historicalRecipient = User::factory()->create([
        'name' => 'Recipient Historical',
        'email' => 'historical@example.test',
    ]);
    User::factory()->create([
        'name' => 'Recipient Candidate',
        'email' => 'candidate@example.test',
    ]);
    User::factory()->create(['name' => 'Unrelated Person']);

    $activeShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($activeRecipient)
        ->create();
    $historicalShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($historicalRecipient)
        ->revoked()
        ->create();

    $response = $this->actingAs($owner)->getJson(route('queries.shares.index', [
        'query' => $query,
        'search' => 'Recipient',
        'per_page' => 1,
    ]));

    $response->assertOk()
        ->assertJsonPath('query.id', $query->id)
        ->assertJsonPath('query.access_level', 'restricted')
        ->assertJsonPath('candidates.meta.per_page', 1)
        ->assertJsonPath('candidates.meta.total', 2)
        ->assertJsonCount(1, 'candidates.data')
        ->assertJsonPath('activeShares.0.id', $activeShare->id)
        ->assertJsonPath('activeShares.0.status', 'active')
        ->assertJsonPath('activeShares.0.user.id', $activeRecipient->id)
        ->assertJsonPath('shareHistory.data.0.id', $historicalShare->id)
        ->assertJsonPath('shareHistory.data.0.is_active', false);

    expect(collect($response->json('candidates.data'))->pluck('id'))
        ->not->toContain($owner->id)
        ->not->toContain($activeRecipient->id);

    $this->actingAs($owner)
        ->get(route('queries.shares.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queries/sharing')
            ->where('query.id', $query->id)
            ->has('permissions', 4)
            ->has('accessLevels', 3));
});

test('creating the first direct share is accepted immediately and atomically restricts a private query', function () {
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();
    $expiresAt = now()->addDay()->toISOString();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::EXECUTE->value,
            'expires_at' => $expiresAt,
        ])
        ->assertCreated()
        ->assertJsonPath('share.user.id', $recipient->id)
        ->assertJsonPath('share.permission', 'execute')
        ->assertJsonPath('share.status', 'active')
        ->assertJsonPath('share.is_active', true);

    $share = QueryUserShare::query()->sole();

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::RESTRICTED)
        ->and($share->accepted_at)->not->toBeNull()
        ->and($share->revoked_at)->toBeNull()
        ->and(Gate::forUser($recipient)->allows('execute', $query))->toBeTrue();

    expect(AuditEvent::query()->where('action', 'query.shared')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.access_level_updated')->count())->toBe(1);
});

test('sharing endpoints reject unauthorized access and nested share IDOR attempts', function () {
    $owner = createConnectedUser();
    $otherOwner = User::factory()->create();
    $intruder = createConnectedUser();
    $recipient = User::factory()->create();
    $firstQuery = Query::factory()->for($owner)->restricted()->create();
    $otherQuery = Query::factory()->for($otherOwner)->restricted()->create();
    $otherShare = QueryUserShare::factory()
        ->for($otherQuery, 'sharedQuery')
        ->for($otherOwner, 'sharedByUser')
        ->for($recipient)
        ->create();

    $this->actingAs($intruder)
        ->getJson(route('queries.shares.index', $firstQuery))
        ->assertForbidden();
    $this->actingAs($intruder)
        ->postJson(route('queries.shares.store', $firstQuery), [
            'user_id' => $recipient->id,
            'permission' => 'view',
        ])
        ->assertForbidden();
    $this->actingAs($intruder)
        ->patchJson(route('queries.access-level', $firstQuery), [
            'access_level' => 'organization',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->patchJson(route('queries.shares.update', [$firstQuery, $otherShare]), [
            'permission' => 'clone',
        ])
        ->assertNotFound();
    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$firstQuery, $otherShare]))
        ->assertNotFound();

    expect($otherShare->refresh()->status)->toBe(QueryUserShare::STATUS_ACCEPTED);
});

test('share expiration must be future and expired grants move to history and lose access', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-18 12:00:00'));
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'view',
            'expires_at' => now()->subMinute()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');

    expect(QueryUserShare::query()->count())->toBe(0);

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'view',
            'expires_at' => now()->addHour()->toISOString(),
        ])
        ->assertCreated();

    expect(Gate::forUser($recipient)->allows('view', $query->refresh()))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-07-18 14:00:00'));

    expect(Gate::forUser($recipient)->allows('view', $query->refresh()))->toBeFalse();

    $this->actingAs($owner)
        ->getJson(route('queries.shares.index', $query))
        ->assertOk()
        ->assertJsonCount(0, 'activeShares')
        ->assertJsonCount(1, 'shareHistory.data')
        ->assertJsonPath('shareHistory.data.0.status', 'expired')
        ->assertJsonPath('shareHistory.data.0.is_active', false);
});

test('revoking a share is idempotent, removes access and retains useful history', function () {
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'clone',
        ])
        ->assertCreated();

    $share = QueryUserShare::query()->sole();

    expect(Gate::forUser($recipient)->allows('clone', $query->refresh()))->toBeTrue();

    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $share]))
        ->assertNoContent();
    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $share]))
        ->assertNoContent();

    expect($share->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($share->revoked_at)->not->toBeNull()
        ->and(Gate::forUser($recipient)->allows('view', $query->refresh()))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'query.share_revoked')->count())->toBe(1);

    $this->actingAs($owner)
        ->getJson(route('queries.shares.index', $query))
        ->assertOk()
        ->assertJsonCount(0, 'activeShares')
        ->assertJsonPath('shareHistory.data.0.id', $share->id);
});

test('every supported direct-share permission is accepted', function (string $permission) {
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => $permission,
        ])
        ->assertCreated()
        ->assertJsonPath('share.permission', $permission);

    expect(QueryUserShare::query()->sole()->permission->value)->toBe($permission);
})->with(array_map(
    fn (QuerySharePermission $permission): string => $permission->value,
    QuerySharePermission::cases(),
));

test('share validation rejects the owner unknown users and unknown permissions', function () {
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $owner->id,
            'permission' => 'view',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => 999999,
            'permission' => 'view',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permission');

    expect(QueryUserShare::query()->count())->toBe(0)
        ->and($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE);
});

test('a delegated manager can administer shares while a view recipient cannot', function () {
    $owner = User::factory()->create();
    $manager = createConnectedUser();
    $viewer = createConnectedUser();
    $newRecipient = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($manager)
        ->permission(QuerySharePermission::MANAGE)
        ->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($viewer)
        ->permission(QuerySharePermission::VIEW)
        ->create();

    $this->actingAs($manager)
        ->getJson(route('queries.shares.index', $query))
        ->assertOk()
        ->assertJsonPath('query.can_change_access_level', false)
        ->assertJsonCount(3, 'permissions')
        ->assertJsonPath('activeShares.0.can_update', false)
        ->assertJsonPath('activeShares.0.can_revoke', false);
    $this->actingAs($manager)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $newRecipient->id,
            'permission' => 'execute',
        ])
        ->assertCreated();
    $this->actingAs($viewer)
        ->getJson(route('queries.shares.index', $query))
        ->assertForbidden();

    $createdShare = QueryUserShare::query()->where('user_id', $newRecipient->id)->sole();

    expect($createdShare->shared_by_user_id)->toBe($manager->id);
});

test('a delegated manager cannot extend or redelegate their own authority', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-18 12:00:00'));
    $owner = User::factory()->create();
    $manager = createConnectedUser();
    $recipient = User::factory()->create();
    $otherManager = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();
    $managerShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($manager)
        ->permission(QuerySharePermission::MANAGE)
        ->create(['expires_at' => now()->addHour()]);
    $otherManagerShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($otherManager)
        ->permission(QuerySharePermission::MANAGE)
        ->create();

    $this->actingAs($manager)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'clone',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');
    $this->actingAs($manager)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'clone',
            'expires_at' => now()->addHours(2)->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expires_at');
    $this->actingAs($manager)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'manage',
            'expires_at' => now()->addMinutes(30)->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permission');
    $this->actingAs($manager)
        ->patchJson(route('queries.access-level', $query), [
            'access_level' => 'organization',
        ])
        ->assertForbidden();
    $this->actingAs($manager)
        ->patchJson(route('queries.shares.update', [$query, $managerShare]), [
            'expires_at' => null,
        ])
        ->assertForbidden();
    $this->actingAs($manager)
        ->deleteJson(route('queries.shares.destroy', [$query, $otherManagerShare]))
        ->assertForbidden();

    $this->actingAs($manager)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'clone',
            'expires_at' => now()->addMinutes(30)->toISOString(),
        ])
        ->assertCreated();

    $delegatedShare = QueryUserShare::query()->where('user_id', $recipient->id)->sole();

    expect($delegatedShare->permission)->toBe(QuerySharePermission::CLONE)
        ->and($delegatedShare->expires_at?->lte($managerShare->expires_at))->toBeTrue();
});

test('a view recipient can inspect the query but cannot run clone or manage it', function () {
    $owner = User::factory()->create();
    $viewer = createConnectedUser();
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($viewer)
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

test('switching to private revokes all active grants atomically and audits the access change', function () {
    $owner = createConnectedUser();
    $query = Query::factory()->for($owner)->restricted()->create();
    $firstRecipient = User::factory()->create();
    $secondRecipient = User::factory()->create();
    $expiredRecipient = User::factory()->create();
    $activeShares = collect([$firstRecipient, $secondRecipient])->map(
        fn (User $recipient) => QueryUserShare::factory()
            ->for($query, 'sharedQuery')
            ->for($owner, 'sharedByUser')
            ->for($recipient)
            ->create(),
    );
    $expiredShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($expiredRecipient)
        ->expired()
        ->create();

    $this->actingAs($owner)
        ->patchJson(route('queries.access-level', $query), [
            'access_level' => 'private',
        ])
        ->assertOk()
        ->assertJsonPath('query.access_level', 'private');

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and($activeShares->map(fn (QueryUserShare $share) => $share->refresh()->status)->all())
        ->toBe([QueryUserShare::STATUS_REVOKED, QueryUserShare::STATUS_REVOKED])
        ->and($expiredShare->refresh()->status)->toBe(QueryUserShare::STATUS_ACCEPTED)
        ->and(AuditEvent::query()->where('action', 'query.share_revoked')->count())->toBe(2);

    $accessEvent = AuditEvent::query()->where('action', 'query.access_level_updated')->sole();

    expect($accessEvent->context)->toMatchArray([
        'before' => 'restricted',
        'after' => 'private',
        'revoked_share_count' => 2,
    ]);
});

test('reapplying private repairs and audits residual active grants', function () {
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->private()->create();
    $share = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($recipient)
        ->permission(QuerySharePermission::MANAGE)
        ->create();

    expect(Gate::forUser($recipient)->allows('view', $query))->toBeFalse();

    $this->actingAs($owner)
        ->patchJson(route('queries.access-level', $query), [
            'access_level' => 'private',
        ])
        ->assertOk()
        ->assertJsonPath('query.access_level', 'private');

    expect($share->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and(AuditEvent::query()->where('action', 'query.share_revoked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.access_level_updated')->count())->toBe(1);
});

test('resharing creates a new lifecycle row and preserves the revoked history', function () {
    $owner = createConnectedUser();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'view',
        ])
        ->assertCreated();
    $firstShare = QueryUserShare::query()->sole();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'execute',
        ])
        ->assertConflict();

    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $firstShare]))
        ->assertNoContent();
    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'execute',
        ])
        ->assertCreated();

    $secondShare = QueryUserShare::query()->whereKeyNot($firstShare->id)->sole();

    expect(QueryUserShare::query()->count())->toBe(2)
        ->and($firstShare->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($secondShare->status)->toBe(QueryUserShare::STATUS_ACCEPTED)
        ->and($secondShare->permission)->toBe(QuerySharePermission::EXECUTE);

    $this->actingAs($owner)
        ->getJson(route('queries.shares.index', $query))
        ->assertOk()
        ->assertJsonPath('activeShares.0.id', $secondShare->id)
        ->assertJsonPath('shareHistory.data.0.id', $firstShare->id);
});

test('share lifecycle audit events contain no recipient names or email addresses', function () {
    $owner = createConnectedUser();
    $recipient = User::factory()->create([
        'name' => 'Sensitive Recipient Name',
        'email' => 'sensitive-recipient@example.test',
    ]);
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.shares.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'view',
        ])
        ->assertCreated();
    $share = QueryUserShare::query()->sole();

    $this->actingAs($owner)
        ->patchJson(route('queries.shares.update', [$query, $share]), [
            'permission' => 'manage',
            'expires_at' => now()->addWeek()->toISOString(),
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->patchJson(route('queries.access-level', $query), [
            'access_level' => 'organization',
        ])
        ->assertOk();
    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $share]))
        ->assertNoContent();

    expect(AuditEvent::query()->where('action', 'query.shared')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'query.share_updated')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'query.share_revoked')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'query.access_level_updated')->exists())->toBeTrue();

    $serializedContexts = json_encode(
        AuditEvent::query()
            ->where('subject_type', $query->getMorphClass())
            ->where('subject_id', $query->id)
            ->pluck('context')
            ->all(),
        JSON_THROW_ON_ERROR,
    );

    expect($serializedContexts)
        ->not->toContain('Sensitive Recipient Name')
        ->not->toContain('sensitive-recipient@example.test');
});
