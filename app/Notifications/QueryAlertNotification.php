<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal, language-neutral notification that one scheduled alert fired.
 *
 * It uses the synchronous database channel so the notification shares the
 * alert-event transaction. Only technical identifiers are stored; display
 * names and the human message are resolved server-side from authorised data.
 */
class QueryAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $alertId,
        public readonly int $scheduleId,
        public readonly int $eventId,
        public readonly int $queryId,
        public readonly string $condition,
        public readonly ?int $observedValue,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'query_alert_triggered';
    }

    /**
     * @return array{
     *     alert_id: int,
     *     schedule_id: int,
     *     event_id: int,
     *     query_id: int,
     *     condition: string,
     *     observed_value: int|null
     * }
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'alert_id' => $this->alertId,
            'schedule_id' => $this->scheduleId,
            'event_id' => $this->eventId,
            'query_id' => $this->queryId,
            'condition' => $this->condition,
            'observed_value' => $this->observedValue,
        ];
    }
}
