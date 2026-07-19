<?php

use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use App\Notifications\QueryShareInvitationNotification;

test('the notification center paginates and exposes only the authenticated users notifications', function () {
    $owner = User::factory()->create();
    $recipient = createConnectedUser();
    $otherRecipient = createConnectedUser();

    foreach (range(1, 3) as $index) {
        $query = Query::factory()->for($owner)->private()->create([
            'name' => "Recipient query {$index}",
        ]);
        $share = QueryUserShare::factory()
            ->for($query, 'sharedQuery')
            ->for($owner, 'sharedByUser')
            ->for($recipient)
            ->pending()
            ->create();

        $recipient->notify(new QueryShareInvitationNotification(
            $share->id,
            $query->id,
            $owner->id,
        ));
    }

    $readNotification = $recipient->notifications()->firstOrFail();
    $readNotification->markAsRead();

    $otherQuery = Query::factory()->for($owner)->private()->create();
    $otherShare = QueryUserShare::factory()
        ->for($otherQuery, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($otherRecipient)
        ->pending()
        ->create();
    $otherRecipient->notify(new QueryShareInvitationNotification(
        $otherShare->id,
        $otherQuery->id,
        $owner->id,
    ));

    $ownNotificationIds = $recipient->notifications()
        ->pluck('id')
        ->map(fn (mixed $id): string => (string) $id)
        ->all();
    $otherNotificationId = (string) $otherRecipient->notifications()->sole()->id;

    $page = $this->actingAs($recipient)
        ->getJson(route('notifications.index', [
            'per_page' => 2,
            'page' => 2,
        ]))
        ->assertOk()
        ->assertJsonPath('filter', 'all')
        ->assertJsonPath('notifications.meta.current_page', 2)
        ->assertJsonPath('notifications.meta.last_page', 2)
        ->assertJsonPath('notifications.meta.per_page', 2)
        ->assertJsonPath('notifications.meta.total', 3)
        ->assertJsonCount(1, 'notifications.data');

    $pageNotification = $page->json('notifications.data.0');

    expect($ownNotificationIds)->toContain((string) $pageNotification['id'])
        ->and((string) $pageNotification['id'])->not->toBe($otherNotificationId)
        ->and($pageNotification['share']['query']['name'])->toStartWith('Recipient query ');

    $unread = $this->actingAs($recipient)
        ->getJson(route('notifications.index', [
            'filter' => 'unread',
            'per_page' => 50,
        ]))
        ->assertOk()
        ->assertJsonPath('filter', 'unread')
        ->assertJsonPath('notifications.meta.total', 2)
        ->assertJsonCount(2, 'notifications.data');

    expect(collect($unread->json('notifications.data'))
        ->every(fn (array $notification): bool => $notification['read_at'] === null))->toBeTrue()
        ->and(collect($unread->json('notifications.data'))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->contains($otherNotificationId))->toBeFalse();

    $this->actingAs($otherRecipient)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('notifications.meta.total', 1)
        ->assertJsonPath('notifications.data.0.id', $otherNotificationId);
});

test('reading one or all notifications is idempotent and isolated by owner', function () {
    $owner = User::factory()->create();
    $recipient = createConnectedUser();
    $otherRecipient = createConnectedUser();

    $firstQuery = Query::factory()->for($owner)->private()->create();
    $firstShare = QueryUserShare::factory()
        ->for($firstQuery, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($recipient)
        ->pending()
        ->create();
    $recipient->notify(new QueryShareInvitationNotification(
        $firstShare->id,
        $firstQuery->id,
        $owner->id,
    ));

    $secondQuery = Query::factory()->for($owner)->private()->create();
    $secondShare = QueryUserShare::factory()
        ->for($secondQuery, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($recipient)
        ->pending()
        ->create();
    $recipient->notify(new QueryShareInvitationNotification(
        $secondShare->id,
        $secondQuery->id,
        $owner->id,
    ));

    $otherQuery = Query::factory()->for($owner)->private()->create();
    $otherShare = QueryUserShare::factory()
        ->for($otherQuery, 'sharedQuery')
        ->for($owner, 'sharedByUser')
        ->for($otherRecipient)
        ->pending()
        ->create();
    $otherRecipient->notify(new QueryShareInvitationNotification(
        $otherShare->id,
        $otherQuery->id,
        $owner->id,
    ));

    $ownNotifications = $recipient->notifications()->get();
    $firstNotification = $ownNotifications->firstOrFail();
    $otherNotification = $otherRecipient->notifications()->sole();

    $this->actingAs($recipient)
        ->patchJson(route('notifications.read', $otherNotification->id))
        ->assertNotFound();

    expect($otherNotification->refresh()->read_at)->toBeNull();

    $this->actingAs($recipient)
        ->patchJson(route('notifications.read', $firstNotification->id))
        ->assertNoContent();
    $this->actingAs($recipient)
        ->patchJson(route('notifications.read', $firstNotification->id))
        ->assertNoContent();

    expect($firstNotification->refresh()->read_at)->not->toBeNull()
        ->and($recipient->unreadNotifications()->count())->toBe(1)
        ->and($otherRecipient->unreadNotifications()->count())->toBe(1);

    $this->actingAs($recipient)
        ->patchJson(route('notifications.read-all'))
        ->assertNoContent();
    $this->actingAs($recipient)
        ->patchJson(route('notifications.read-all'))
        ->assertNoContent();

    expect($recipient->unreadNotifications()->count())->toBe(0)
        ->and($recipient->notifications()->whereNull('read_at')->exists())->toBeFalse()
        ->and($otherRecipient->unreadNotifications()->count())->toBe(1)
        ->and($otherNotification->refresh()->read_at)->toBeNull();
});

test('stored invitation notifications contain internal identifiers only', function () {
    $owner = createConnectedUser([
        'name' => 'Sensitive Owner Name',
        'email' => 'sensitive-owner@example.test',
    ]);
    $recipient = createConnectedUser([
        'name' => 'Sensitive Recipient Name',
        'email' => 'sensitive-recipient@example.test',
    ]);
    $unrelatedUser = createConnectedUser();
    $query = Query::factory()->for($owner)->private()->create([
        'name' => 'Secret invoice query',
        'description' => 'Supplier threshold only finance may know',
        'resource_path' => '/confidential/suppliers',
        'tenant_key' => 'confidential_tenant',
    ]);

    $this->actingAs($owner)
        ->postJson(route('queries.invitations.store', $query), [
            'user_id' => $recipient->id,
            'permission' => 'view',
        ])
        ->assertCreated();

    $share = QueryUserShare::query()->sole();
    $notification = $recipient->notifications()->sole();

    expect($notification->type)->toBe('query_share_invitation')
        ->and($notification->data)->toBe([
            'share_id' => $share->id,
            'query_id' => $query->id,
            'invited_by_user_id' => $owner->id,
        ])
        ->and(array_keys($notification->data))->toBe([
            'share_id',
            'query_id',
            'invited_by_user_id',
        ]);

    $storedData = json_encode($notification->data, JSON_THROW_ON_ERROR);

    expect($storedData)
        ->not->toContain($owner->name)
        ->not->toContain($owner->email)
        ->not->toContain($recipient->name)
        ->not->toContain($recipient->email)
        ->not->toContain($query->name)
        ->not->toContain((string) $query->description)
        ->not->toContain((string) $query->resource_path)
        ->not->toContain((string) $query->tenant_key);

    $this->actingAs($unrelatedUser)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('notifications.meta.total', 0)
        ->assertJsonCount(0, 'notifications.data');

    $this->actingAs($unrelatedUser)
        ->patchJson(route('notifications.read', $notification->id))
        ->assertNotFound();
});
