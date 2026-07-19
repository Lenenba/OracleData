<?php

use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

test('creating an invitation records only technical data and grants no access before consent', function () {
    $now = CarbonImmutable::parse('2026-07-18 12:00:00 UTC');
    $this->travelTo($now);

    $owner = createConnectedUser();
    $recipient = User::factory()->create([
        'name' => 'Confidential Recipient',
        'email' => 'confidential-recipient@example.test',
    ]);
    $query = Query::factory()->for($owner)->private()->create([
        'name' => 'Confidential supplier invoices',
        'description' => 'Internal amount threshold',
        'tenant_key' => 'sensitive_tenant',
    ]);
    $respondBy = $now->addDays(2);
    $expiresAt = $now->addDays(10);

    $this->actingAs($owner)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::EXECUTE->value,
            'respond_by' => $respondBy->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonPath('invitation.status', QueryUserShare::STATUS_PENDING)
        ->assertJsonPath('invitation.permission', QuerySharePermission::EXECUTE->value)
        ->assertJsonPath('invitation.is_pending', true)
        ->assertJsonPath('invitation.is_active', false);

    $share = QueryUserShare::query()->sole();
    $notification = $recipient->notifications()->sole();
    $audit = AuditEvent::query()
        ->where('action', 'query.share_invited')
        ->sole();

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and($share->status)->toBe(QueryUserShare::STATUS_PENDING)
        ->and($share->respond_by?->equalTo($respondBy))->toBeTrue()
        ->and($share->expires_at?->equalTo($expiresAt))->toBeTrue()
        ->and($share->respond_by?->equalTo($share->expires_at))->toBeFalse()
        ->and($share->accepted_at)->toBeNull()
        ->and(Gate::forUser($recipient)->allows('view', $query))->toBeFalse()
        ->and(Gate::forUser($recipient)->allows('execute', $query))->toBeFalse()
        ->and(Query::query()->accessibleTo($recipient)->whereKey($query->id)->exists())->toBeFalse()
        ->and($notification->type)->toBe('query_share_invitation')
        ->and($notification->data)->toBe([
            'share_id' => $share->id,
            'query_id' => $query->id,
            'invited_by_user_id' => $owner->id,
        ])
        ->and($audit->user_id)->toBe($owner->id)
        ->and($audit->context)->toMatchArray([
            'share_id' => $share->id,
            'recipient_user_id' => $recipient->id,
            'permission' => QuerySharePermission::EXECUTE->value,
            'respond_by' => $respondBy->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ])
        ->and(AuditEvent::query()->where('action', 'query.access_level_updated')->exists())->toBeFalse();

    $serializedTechnicalData = json_encode([
        'notification' => $notification->data,
        'audit' => $audit->context,
    ], JSON_THROW_ON_ERROR);

    expect($serializedTechnicalData)
        ->not->toContain($recipient->name)
        ->not->toContain($recipient->email)
        ->not->toContain($query->name)
        ->not->toContain((string) $query->description)
        ->not->toContain((string) $query->resource_path)
        ->not->toContain((string) $query->tenant_key);
});

test('the intended recipient can accept once while replay remains idempotent', function () {
    $owner = createConnectedUser();
    $recipient = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::EXECUTE->value,
        ])
        ->assertCreated();

    $share = QueryUserShare::query()->sole();
    $notification = $recipient->notifications()->sole();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertOk()
        ->assertJsonPath('query_id', $query->id)
        ->assertJsonPath('share.status', QueryUserShare::STATUS_ACCEPTED)
        ->assertJsonPath('share.is_active', true)
        ->assertJsonPath('share.is_pending', false);

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertOk()
        ->assertJsonPath('share.status', QueryUserShare::STATUS_ACCEPTED);

    expect($query->refresh()->access_level)->toBe(QueryAccessLevel::RESTRICTED)
        ->and($share->refresh()->status)->toBe(QueryUserShare::STATUS_ACCEPTED)
        ->and($share->accepted_at)->not->toBeNull()
        ->and($share->isActive())->toBeTrue()
        ->and(Gate::forUser($recipient)->allows('execute', $query))->toBeTrue()
        ->and($notification->refresh()->read_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'query.share_invitation_accepted')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.access_level_updated')->count())->toBe(1);

    $responseNotification = $owner->notifications()->sole();

    expect($responseNotification->type)->toBe('query_share_invitation_accepted')
        ->and($responseNotification->data)->toBe([
            'share_id' => $share->id,
            'query_id' => $query->id,
            'responded_by_user_id' => $recipient->id,
        ])
        ->and($owner->notifications()->count())->toBe(1);

    $accepted = AuditEvent::query()
        ->where('action', 'query.share_invitation_accepted')
        ->sole();

    expect($accepted->user_id)->toBe($recipient->id)
        ->and($accepted->context)->toMatchArray([
            'share_id' => $share->id,
            'recipient_user_id' => $recipient->id,
            'permission' => QuerySharePermission::EXECUTE->value,
        ]);
});

test('declining is idempotent and permanently prevents a later acceptance', function () {
    $owner = createConnectedUser();
    $recipient = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::CLONE->value,
        ])
        ->assertCreated();

    $share = QueryUserShare::query()->sole();
    $notification = $recipient->notifications()->sole();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.decline', $share))
        ->assertNoContent();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.decline', $share))
        ->assertNoContent();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertConflict();

    expect($share->refresh()->status)->toBe(QueryUserShare::STATUS_DECLINED)
        ->and($share->declined_at)->not->toBeNull()
        ->and($share->accepted_at)->toBeNull()
        ->and($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and(Gate::forUser($recipient)->allows('view', $query))->toBeFalse()
        ->and($notification->refresh()->read_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'query.share_invitation_declined')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.share_invitation_accepted')->exists())->toBeFalse();

    $responseNotification = $owner->notifications()->sole();

    expect($responseNotification->type)->toBe('query_share_invitation_declined')
        ->and($responseNotification->data)->toBe([
            'share_id' => $share->id,
            'query_id' => $query->id,
            'responded_by_user_id' => $recipient->id,
        ])
        ->and($owner->notifications()->count())->toBe(1);
});

test('an unanswered invitation expires from respond by without becoming a grant', function () {
    $now = CarbonImmutable::parse('2026-07-18 12:00:00 UTC');
    $this->travelTo($now);

    $owner = User::factory()->create();
    $recipient = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create();
    $share = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($recipient)
        ->pending()
        ->create([
            'respond_by' => $now->addHour(),
            'expires_at' => $now->addWeek(),
        ]);

    $this->travelTo($now->addHours(2));

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertConflict();
    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.decline', $share))
        ->assertConflict();

    expect($share->refresh()->status)->toBe(QueryUserShare::STATUS_PENDING)
        ->and($share->isPending())->toBeFalse()
        ->and($share->isActive())->toBeFalse()
        ->and($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and(Gate::forUser($recipient)->allows('view', $query))->toBeFalse()
        ->and(AuditEvent::query()->whereIn('action', [
            'query.share_invitation_accepted',
            'query.share_invitation_declined',
        ])->exists())->toBeFalse();
});

test('invitation responses are scoped to the intended recipient', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $intruder = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create();
    $share = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($recipient)
        ->pending()
        ->create();

    $this->actingAs($intruder)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertNotFound();
    $this->actingAs($intruder)
        ->postJson(route('query-share-invitations.decline', $share))
        ->assertNotFound();

    expect($share->refresh()->status)->toBe(QueryUserShare::STATUS_PENDING)
        ->and($share->isPending())->toBeTrue()
        ->and($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and(Gate::forUser($intruder)->allows('view', $query))->toBeFalse();
});

test('acceptance revalidates a delegated managers authority', function () {
    $owner = createConnectedUser();
    $manager = createConnectedUser();
    $recipient = createConnectedUser();
    $forbiddenRecipient = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();
    $managerShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($manager)
        ->permission(QuerySharePermission::MANAGE)
        ->create();

    $this->actingAs($manager)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $forbiddenRecipient->id,
            'permission' => QuerySharePermission::MANAGE->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permission');

    $this->actingAs($manager)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::EXECUTE->value,
        ])
        ->assertCreated();

    $invitation = QueryUserShare::query()
        ->where('user_id', $recipient->id)
        ->sole();
    $notification = $recipient->notifications()->sole();

    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $managerShare]))
        ->assertNoContent();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $invitation))
        ->assertConflict();

    expect($managerShare->refresh()->status)->toBe(QueryUserShare::STATUS_REVOKED)
        ->and($invitation->refresh()->status)->toBe(QueryUserShare::STATUS_CANCELLED)
        ->and($invitation->cancelled_at)->not->toBeNull()
        ->and($notification->refresh()->read_at)->not->toBeNull()
        ->and(Gate::forUser($recipient)->allows('execute', $query))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'query.share_invitation_accepted')->exists())->toBeFalse();

    $cancelled = AuditEvent::query()
        ->where('action', 'query.share_invitation_cancelled')
        ->sole();

    expect($cancelled->user_id)->toBe($recipient->id)
        ->and($cancelled->context)->toMatchArray([
            'share_id' => $invitation->id,
            'recipient_user_id' => $recipient->id,
            'reason' => 'inviter_authority_lost',
        ]);
});

test('returning to private and archiving both cancel pending invitations', function () {
    $owner = createConnectedUser();
    $privateRecipient = User::factory()->create();
    $archivedRecipient = User::factory()->create();
    $privateQuery = Query::factory()->for($owner)->private()->create();
    $archivedQuery = Query::factory()->for($owner)->restricted()->create();
    $privateInvitation = QueryUserShare::factory()
        ->for($privateQuery, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($privateRecipient)
        ->pending()
        ->create();
    $archivedInvitation = QueryUserShare::factory()
        ->for($archivedQuery, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($archivedRecipient)
        ->pending()
        ->create();

    $this->actingAs($owner)
        ->patchJson(route('queries.access-level', $privateQuery), [
            'access_level' => QueryAccessLevel::PRIVATE->value,
        ])
        ->assertOk()
        ->assertJsonPath('access_level', QueryAccessLevel::PRIVATE->value);

    $this->actingAs($owner)
        ->delete(route('queries.destroy', $archivedQuery))
        ->assertRedirect(route('queries.index'));

    expect($privateInvitation->refresh()->status)->toBe(QueryUserShare::STATUS_CANCELLED)
        ->and($privateInvitation->cancelled_at)->not->toBeNull()
        ->and($privateQuery->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and($archivedInvitation->refresh()->status)->toBe(QueryUserShare::STATUS_CANCELLED)
        ->and($archivedInvitation->cancelled_at)->not->toBeNull()
        ->and(Query::query()->find($archivedQuery->id))->toBeNull()
        ->and(Query::withTrashed()->find($archivedQuery->id))->not->toBeNull();

    $privateCancellation = AuditEvent::query()
        ->where('action', 'query.share_invitation_cancelled')
        ->where('subject_id', $privateQuery->id)
        ->sole();
    $archiveCancellation = AuditEvent::query()
        ->where('action', 'query.share_invitation_cancelled')
        ->where('subject_id', $archivedQuery->id)
        ->sole();
    $privateAccessUpdate = AuditEvent::query()
        ->where('action', 'query.access_level_updated')
        ->where('subject_id', $privateQuery->id)
        ->sole();
    $archiveEvent = AuditEvent::query()
        ->where('action', 'query.archived')
        ->where('subject_id', $archivedQuery->id)
        ->sole();

    expect($privateCancellation->context['reason'])->toBe('access_level_private')
        ->and($archiveCancellation->context['reason'])->toBe('query_archived')
        ->and($privateAccessUpdate->context)->toMatchArray([
            'revoked_share_count' => 1,
            'revoked_user_share_count' => 0,
            'revoked_group_share_count' => 0,
            'cancelled_invitation_count' => 1,
        ])
        ->and($archiveEvent->context)->toMatchArray([
            'revoked_share_count' => 1,
            'revoked_user_share_count' => 0,
            'revoked_group_share_count' => 0,
            'cancelled_invitation_count' => 1,
        ]);
});

test('the existing share deletion endpoint cancels a pending invitation idempotently', function () {
    $owner = createConnectedUser();
    $recipient = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::VIEW->value,
        ])
        ->assertCreated();

    $invitation = QueryUserShare::query()->sole();

    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $invitation]))
        ->assertNoContent();
    $this->actingAs($owner)
        ->deleteJson(route('queries.shares.destroy', [$query, $invitation]))
        ->assertNoContent();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $invitation))
        ->assertConflict();

    expect($invitation->refresh()->status)->toBe(QueryUserShare::STATUS_CANCELLED)
        ->and($invitation->cancelled_at)->not->toBeNull()
        ->and($query->refresh()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and(Gate::forUser($recipient)->allows('view', $query))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'query.share_invitation_cancelled')->count())->toBe(1);

    $cancelled = AuditEvent::query()
        ->where('action', 'query.share_invitation_cancelled')
        ->sole();

    expect($cancelled->user_id)->toBe($owner->id)
        ->and($cancelled->context)->toMatchArray([
            'share_id' => $invitation->id,
            'recipient_user_id' => $recipient->id,
            'reason' => 'manual_cancellation',
        ]);
});
