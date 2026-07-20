<?php

namespace App\Services;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\QueryAlert;
use App\Models\QueryAlertEvent;
use App\Models\QuerySchedule;
use App\Models\WebhookEndpoint;

/**
 * Fans one internal event out to every active endpoint of the owning user that
 * subscribes to it. Payloads carry technical metadata only, never Oracle data.
 */
class WebhookDispatcher
{
    public function dispatchAlertEvent(
        QuerySchedule $schedule,
        QueryAlert $alert,
        QueryAlertEvent $event,
    ): void {
        $this->dispatch($alert->user_id, WebhookEvent::AlertTriggered->value, [
            'event' => WebhookEvent::AlertTriggered->value,
            'alert' => [
                'id' => $alert->id,
                'name' => $alert->name,
                'condition' => $alert->condition->value,
                'threshold' => $alert->threshold,
            ],
            'schedule' => [
                'id' => $schedule->id,
                'name' => $schedule->name,
            ],
            'query' => ['id' => $schedule->query_id],
            'observed_value' => $event->observed_value,
            'triggered_at' => $event->triggered_at->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(int $userId, string $event, array $payload): void
    {
        WebhookEndpoint::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event))
            ->each(fn (WebhookEndpoint $endpoint) => DeliverWebhook::dispatch($endpoint, $event, $payload));
    }
}
