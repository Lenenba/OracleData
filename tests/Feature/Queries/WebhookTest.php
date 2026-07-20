<?php

use App\Enums\AlertCondition;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\QueryAlert;
use App\Models\QueryAlertEvent;
use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\QueryAlertEvaluator;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->owner = User::factory()->create();
});

test('a delivery signs the body with the endpoint secret and records success', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    $endpoint = WebhookEndpoint::factory()->for($this->owner)->create([
        'url' => 'https://hooks.example.com/target',
        'secret' => 'supersecretvalue123',
    ]);
    $payload = ['event' => 'query.alert_triggered', 'foo' => 'bar'];

    app()->call([new DeliverWebhook($endpoint, 'query.alert_triggered', $payload), 'handle']);

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $expected = 'sha256='.hash_hmac('sha256', (string) $body, 'supersecretvalue123');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.com/target'
        && $request->hasHeader('X-OracleData-Signature', $expected)
        && $request->hasHeader('X-OracleData-Event', 'query.alert_triggered'));

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->status_code)->toBe(200)
        ->and($endpoint->refresh()->last_delivered_at)->not->toBeNull();
});

test('a delivery records a failure when the endpoint rejects it', function () {
    Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);
    $endpoint = WebhookEndpoint::factory()->for($this->owner)->create([
        'url' => 'https://hooks.example.com/target',
    ]);

    app()->call([new DeliverWebhook($endpoint, 'query.alert_triggered', ['event' => 'x']), 'handle']);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->status_code)->toBe(500)
        ->and($delivery->attempts)->toBeGreaterThan(1)
        ->and($endpoint->refresh()->last_delivered_at)->toBeNull();
});

test('an inactive endpoint is skipped without a request', function () {
    Http::fake();
    $endpoint = WebhookEndpoint::factory()->for($this->owner)->inactive()->create();

    app()->call([new DeliverWebhook($endpoint, 'query.alert_triggered', ['event' => 'x']), 'handle']);

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

test('the dispatcher only queues active endpoints subscribed to the event', function () {
    Queue::fake();
    $schedule = QuerySchedule::factory()->for($this->owner)->create();
    $alert = QueryAlert::factory()->for($this->owner)->create(['query_schedule_id' => $schedule->id]);
    $event = QueryAlertEvent::factory()->for($this->owner)->create(['query_alert_id' => $alert->id]);

    WebhookEndpoint::factory()->for($this->owner)->create(); // subscribed + active
    WebhookEndpoint::factory()->for($this->owner)->subscribedTo([])->create(); // not subscribed
    WebhookEndpoint::factory()->for($this->owner)->inactive()->create(); // inactive
    WebhookEndpoint::factory()->create(); // another user

    app(WebhookDispatcher::class)->dispatchAlertEvent($schedule, $alert, $event);

    Queue::assertPushed(DeliverWebhook::class, 1);
});

test('a triggered alert fans out to a webhook', function () {
    Queue::fake();
    $schedule = QuerySchedule::factory()->for($this->owner)->create();
    $alert = QueryAlert::factory()->for($this->owner)->condition(AlertCondition::RowCountAbove, 1)
        ->create(['query_schedule_id' => $schedule->id]);
    WebhookEndpoint::factory()->for($this->owner)->create();
    $run = QueryScheduleRun::factory()->for($this->owner)->create([
        'query_schedule_id' => $schedule->id,
        'status' => QuerySchedule::STATUS_SUCCEEDED,
        'row_count' => 5,
    ]);

    app(QueryAlertEvaluator::class)->evaluate($schedule, $run);

    Queue::assertPushed(DeliverWebhook::class, 1);
});

test('the owner can register a webhook endpoint and its secret stays hidden', function () {
    $this->actingAs($this->owner)
        ->post(route('webhooks.store'), [
            'name' => 'Mon intégration',
            'url' => 'https://hooks.example.com/in',
            'secret' => 'a-long-enough-secret-value',
            'events' => [WebhookEvent::AlertTriggered->value],
        ])
        ->assertRedirect(route('automation.index'));

    $endpoint = WebhookEndpoint::query()->sole();
    expect($endpoint->user_id)->toBe($this->owner->id)
        ->and($endpoint->secret)->toBe('a-long-enough-secret-value');

    $this->actingAs($this->owner)
        ->get(route('automation.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('webhooks.0')
            ->missing('webhooks.0.secret'));
});

test('a webhook url must be https and a secret is required at creation', function () {
    $this->actingAs($this->owner)
        ->post(route('webhooks.store'), [
            'name' => 'Insecure',
            'url' => 'http://hooks.example.com/in',
            'events' => [WebhookEvent::AlertTriggered->value],
        ])
        ->assertSessionHasErrors(['url', 'secret']);
});

test('a webhook endpoint cannot be updated or deleted by another user', function () {
    $endpoint = WebhookEndpoint::factory()->for($this->owner)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patch(route('webhooks.update', $endpoint), [
            'name' => 'Pirate',
            'url' => 'https://evil.example.com/in',
            'events' => [WebhookEvent::AlertTriggered->value],
        ])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->delete(route('webhooks.destroy', $endpoint))
        ->assertNotFound();
});
