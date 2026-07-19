<?php

use App\Enums\QueryChangeRequestStatus;
use App\Enums\QuerySharePermission;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\QueryChangeRequestComment;
use App\Models\QueryUserShare;
use App\Models\User;

test('a current non owner reader creates a request whose first comment mentions authorized readers only', function () {
    $owner = createConnectedUser();
    $requester = createConnectedUser();
    $mentioned = User::factory()->create();
    $query = Query::factory()->for($owner)->organization()->create([
        'name' => 'Sensitive supplier query',
    ]);

    $this->actingAs($requester)
        ->postJson(route('queries.change-requests.store', $query), [
            'title' => 'Add the supplier site',
            'message' => 'Please add the supplier site column.',
            'mentioned_user_ids' => [$mentioned->id],
        ])
        ->assertCreated()
        ->assertJsonPath('changeRequest.title', 'Add the supplier site')
        ->assertJsonPath('changeRequest.status', QueryChangeRequestStatus::PENDING->value)
        ->assertJsonPath('changeRequest.comment_count', 1)
        ->assertJsonPath('changeRequest.status_changed_by', null)
        ->assertJsonPath('changeRequest.status_changed_at', null);

    $changeRequest = QueryChangeRequest::query()->sole();
    $comment = QueryChangeRequestComment::query()->with('mentions')->sole();
    $ownerNotification = $owner->notifications()->sole();
    $mentionNotification = $mentioned->notifications()->sole();
    $audit = AuditEvent::query()->where('action', 'query.change_request_created')->sole();

    expect($changeRequest->requested_by_user_id)->toBe($requester->id)
        ->and($changeRequest->status)->toBe(QueryChangeRequestStatus::PENDING)
        ->and($changeRequest->status_changed_by_user_id)->toBeNull()
        ->and($changeRequest->status_changed_at)->toBeNull()
        ->and($comment->body)->toBe('Please add the supplier site column.')
        ->and($comment->mentions->modelKeys())->toBe([$mentioned->id])
        ->and($requester->notifications()->count())->toBe(0)
        ->and($ownerNotification->type)->toBe('query_change_request_created')
        ->and($mentionNotification->type)->toBe('query_change_request_mentioned')
        ->and($ownerNotification->data)->toBe([
            'change_request_id' => $changeRequest->id,
            'query_id' => $query->id,
            'actor_user_id' => $requester->id,
            'comment_id' => $comment->id,
        ])
        ->and(array_keys($mentionNotification->data))->toBe([
            'change_request_id',
            'query_id',
            'actor_user_id',
            'comment_id',
        ])
        ->and($audit->context)->toMatchArray([
            'query_id' => $query->id,
            'requester_user_id' => $requester->id,
            'comment_id' => $comment->id,
            'mentioned_user_ids' => [$mentioned->id],
            'status' => QueryChangeRequestStatus::PENDING->value,
        ]);

    $technicalData = json_encode([
        $ownerNotification->data,
        $mentionNotification->data,
        $audit->context,
    ], JSON_THROW_ON_ERROR);

    expect($technicalData)
        ->not->toContain('Add the supplier site')
        ->not->toContain('Please add')
        ->not->toContain($query->name)
        ->not->toContain($requester->email);
});

test('creation and thread visibility follow current query access and nested resources reject idor', function () {
    $owner = createConnectedUser();
    $requester = createConnectedUser();
    $otherReader = createConnectedUser();
    $outsider = createConnectedUser();
    $query = Query::factory()->for($owner)->restricted()->create();
    $otherQuery = Query::factory()->for($owner)->organization()->create();
    $requesterShare = QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($requester)
        ->create();
    QueryUserShare::factory()
        ->for($query, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($otherReader)
        ->create();

    $this->actingAs($owner)
        ->postJson(route('queries.change-requests.store', $query), [
            'title' => 'Owner request',
            'message' => 'Owners edit directly.',
        ])
        ->assertForbidden();
    $this->actingAs($outsider)
        ->postJson(route('queries.change-requests.store', $query), [
            'title' => 'Unauthorized',
            'message' => 'No access.',
        ])
        ->assertForbidden();

    $this->actingAs($requester)
        ->postJson(route('queries.change-requests.store', $query), [
            'title' => 'Authorized request',
            'message' => 'Add a total.',
        ])
        ->assertCreated();
    $changeRequest = QueryChangeRequest::query()->sole();

    $this->actingAs($otherReader)
        ->getJson(route('queries.change-requests.index', $query))
        ->assertOk()
        ->assertJsonPath('changeRequests.meta.total', 1)
        ->assertJsonPath('changeRequests.data.0.id', $changeRequest->id)
        ->assertJsonPath('changeRequests.data.0.can.view', true);
    $this->actingAs($otherReader)
        ->getJson(route('queries.change-requests.show', [$otherQuery, $changeRequest]))
        ->assertNotFound();

    $requesterShare->revoke();

    $this->actingAs($requester)
        ->getJson(route('queries.change-requests.index', $query))
        ->assertForbidden();
});

test('comments are immutable and mention notifications take priority without duplicates', function () {
    $owner = createConnectedUser();
    $requester = createConnectedUser();
    $commenter = createConnectedUser();
    $inaccessible = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();

    foreach ([$requester, $commenter] as $reader) {
        QueryUserShare::factory()
            ->for($query, 'sharedQuery')
            ->for($owner, 'sharedByUser')
            ->for($reader)
            ->create();
    }

    $changeRequest = QueryChangeRequest::factory()
        ->for($query, 'subjectQuery')
        ->for($requester, 'requestedBy')
        ->create();

    $this->actingAs($commenter)
        ->postJson(route('queries.change-requests.comments.store', [$query, $changeRequest]), [
            'body' => 'Owner, could you confirm this rule?',
            'mentioned_user_ids' => [$owner->id],
        ])
        ->assertCreated()
        ->assertJsonPath('comment.mentions.0.id', $owner->id);

    $comment = QueryChangeRequestComment::query()->sole();

    expect($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->sole()->type)->toBe('query_change_request_mentioned')
        ->and($requester->notifications()->count())->toBe(1)
        ->and($requester->notifications()->sole()->type)->toBe('query_change_request_commented')
        ->and($commenter->notifications()->count())->toBe(0);

    $this->actingAs($commenter)
        ->postJson(route('queries.change-requests.comments.store', [$query, $changeRequest]), [
            'body' => 'This mention is forbidden.',
            'mentioned_user_ids' => [$inaccessible->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mentioned_user_ids');
    $this->actingAs($commenter)
        ->postJson(route('queries.change-requests.comments.store', [$query, $changeRequest]), [
            'body' => 'Self mention.',
            'mentioned_user_ids' => [$commenter->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mentioned_user_ids');

    expect(fn () => $comment->update(['body' => 'Mutated']))
        ->toThrow(LogicException::class)
        ->and(fn () => $comment->delete())
        ->toThrow(LogicException::class);
});

test('the owner accepts and completes a request while status replay is idempotent', function () {
    $owner = createConnectedUser();
    $requester = createConnectedUser();
    $query = Query::factory()->for($owner)->organization()->create();
    $changeRequest = QueryChangeRequest::factory()
        ->for($query, 'subjectQuery')
        ->for($requester, 'requestedBy')
        ->create();

    $this->actingAs($owner)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::ACCEPTED->value,
            'response' => 'I will add this column.',
        ])
        ->assertOk()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('changeRequest.status', QueryChangeRequestStatus::ACCEPTED->value)
        ->assertJsonPath('changeRequest.status_changed_by.id', $owner->id);
    $this->actingAs($owner)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::ACCEPTED->value,
            'response' => 'This replay must not create another comment.',
        ])
        ->assertOk()
        ->assertJsonPath('changed', false);

    expect($changeRequest->refresh()->status)->toBe(QueryChangeRequestStatus::ACCEPTED)
        ->and($changeRequest->status_changed_by_user_id)->toBe($owner->id)
        ->and($changeRequest->comments()->count())->toBe(1)
        ->and($requester->notifications()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.change_request_status_changed')->count())->toBe(1);

    $this->actingAs($owner)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::COMPLETED->value,
        ])
        ->assertOk()
        ->assertJsonPath('changed', true);
    $this->actingAs($owner)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::REJECTED->value,
        ])
        ->assertConflict();
    $this->actingAs($requester)
        ->postJson(route('queries.change-requests.comments.store', [$query, $changeRequest]), [
            'body' => 'A terminal request cannot be changed.',
        ])
        ->assertForbidden();
});

test('a requester cancellation can be replayed but no reader can perform owner transitions', function () {
    $owner = createConnectedUser();
    $requester = createConnectedUser();
    $otherReader = createConnectedUser();
    $query = Query::factory()->for($owner)->organization()->create();
    $changeRequest = QueryChangeRequest::factory()
        ->for($query, 'subjectQuery')
        ->for($requester, 'requestedBy')
        ->create();

    $this->actingAs($otherReader)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::ACCEPTED->value,
        ])
        ->assertForbidden();
    $this->actingAs($owner)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::COMPLETED->value,
        ])
        ->assertConflict();
    $this->actingAs($requester)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::CANCELLED->value,
        ])
        ->assertOk()
        ->assertJsonPath('changed', true);
    $this->actingAs($requester)
        ->patchJson(route('queries.change-requests.update', [$query, $changeRequest]), [
            'status' => QueryChangeRequestStatus::CANCELLED->value,
        ])
        ->assertOk()
        ->assertJsonPath('changed', false);

    expect($changeRequest->refresh()->status)->toBe(QueryChangeRequestStatus::CANCELLED)
        ->and($owner->notifications()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.change_request_status_changed')->count())->toBe(1);
});

test('model status and terminal comments cannot bypass the governed lifecycle', function () {
    $owner = User::factory()->create();
    $requester = User::factory()->create();
    $query = Query::factory()->for($owner)->organization()->create();
    $changeRequest = QueryChangeRequest::factory()
        ->for($query, 'subjectQuery')
        ->for($requester, 'requestedBy')
        ->create();

    expect(fn () => $changeRequest->forceFill([
        'status' => QueryChangeRequestStatus::REJECTED,
    ])->save())->toThrow(LogicException::class);

    $changeRequest->refresh()->transitionTo(QueryChangeRequestStatus::ACCEPTED, $owner);
    $changeRequest->transitionTo(QueryChangeRequestStatus::COMPLETED, $owner);

    expect(fn () => $changeRequest->transitionTo(QueryChangeRequestStatus::REJECTED, $owner))
        ->toThrow(InvalidArgumentException::class);
});

test('the notification center exposes normalized change request activity without comment contents', function () {
    $owner = createConnectedUser();
    $requester = createConnectedUser();
    $query = Query::factory()->for($owner)->organization()->create();

    $this->actingAs($requester)
        ->postJson(route('queries.change-requests.store', $query), [
            'title' => 'Normalize totals',
            'message' => 'Secret explanatory comment.',
        ])
        ->assertCreated();
    $changeRequest = QueryChangeRequest::query()->sole();

    $response = $this->actingAs($owner)->getJson(route('notifications.index'));

    $response->assertOk()
        ->assertJsonPath('notifications.data.0.kind', 'query_change_request_created')
        ->assertJsonPath('notifications.data.0.actor.id', $requester->id)
        ->assertJsonPath('notifications.data.0.query.id', $query->id)
        ->assertJsonPath('notifications.data.0.is_available', true)
        ->assertJsonPath('notifications.data.0.change_request.id', $changeRequest->id)
        ->assertJsonPath('notifications.data.0.change_request.can_view', true)
        ->assertJsonPath('notifications.data.0.share', null);

    expect(json_encode($response->json('notifications.data.0'), JSON_THROW_ON_ERROR))
        ->not->toContain('Secret explanatory comment.');
});

test('archiving a query atomically cancels pending and accepted change requests', function () {
    $owner = createConnectedUser();
    $pendingRequester = createConnectedUser();
    $acceptedRequester = createConnectedUser();
    $query = Query::factory()->for($owner)->organization()->create();
    $pending = QueryChangeRequest::factory()
        ->for($query, 'subjectQuery')
        ->for($pendingRequester, 'requestedBy')
        ->create();
    $accepted = QueryChangeRequest::factory()
        ->for($query, 'subjectQuery')
        ->for($acceptedRequester, 'requestedBy')
        ->accepted($owner)
        ->create();

    $this->actingAs($owner)
        ->delete(route('queries.destroy', $query))
        ->assertRedirect(route('queries.index'));

    $cancelAudits = AuditEvent::query()
        ->where('action', 'query.change_request_status_changed')
        ->get();

    expect($pending->refresh()->status)->toBe(QueryChangeRequestStatus::CANCELLED)
        ->and($accepted->refresh()->status)->toBe(QueryChangeRequestStatus::CANCELLED)
        ->and($pendingRequester->notifications()->count())->toBe(1)
        ->and($acceptedRequester->notifications()->count())->toBe(1)
        ->and($cancelAudits)->toHaveCount(2)
        ->and($cancelAudits->every(
            fn (AuditEvent $event): bool => $event->context['reason'] === 'query_archived',
        ))->toBeTrue();

    $archive = AuditEvent::query()->where('action', 'query.archived')->sole();

    expect($archive->context['cancelled_change_request_count'])->toBe(2);

    $redactedCenter = $this->actingAs($pendingRequester)
        ->getJson(route('notifications.index'));

    $redactedCenter->assertOk()
        ->assertJsonPath('notifications.data.0.kind', 'query_change_request_status_changed')
        ->assertJsonPath('notifications.data.0.is_available', false)
        ->assertJsonPath('notifications.data.0.query', null)
        ->assertJsonPath('notifications.data.0.change_request', null);

    expect(json_encode($redactedCenter->json('notifications.data.0'), JSON_THROW_ON_ERROR))
        ->not->toContain($query->name)
        ->not->toContain($pending->title);
});

test('an accepted invitation stays available as a query link and its sender sees one normalized response', function () {
    $owner = createConnectedUser();
    $recipient = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create();

    $this->actingAs($owner)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => QuerySharePermission::VIEW->value,
        ])
        ->assertCreated();
    $share = QueryUserShare::query()->sole();

    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertOk();
    $this->actingAs($recipient)
        ->postJson(route('query-share-invitations.accept', $share))
        ->assertOk();

    $recipientCenter = $this->actingAs($recipient)->getJson(route('notifications.index'));
    $recipientCenter->assertOk()
        ->assertJsonPath('notifications.data.0.kind', 'query_share_invitation')
        ->assertJsonPath('notifications.data.0.is_available', true)
        ->assertJsonPath('notifications.data.0.share.can_accept', false)
        ->assertJsonPath('notifications.data.0.query.id', $query->id);

    $senderCenter = $this->actingAs($owner)->getJson(route('notifications.index'));
    $senderCenter->assertOk()
        ->assertJsonPath('notifications.meta.total', 1)
        ->assertJsonPath('notifications.data.0.kind', 'query_share_invitation_accepted')
        ->assertJsonPath('notifications.data.0.actor.id', $recipient->id)
        ->assertJsonPath('notifications.data.0.share.id', $share->id)
        ->assertJsonPath('notifications.data.0.is_available', true);
});
