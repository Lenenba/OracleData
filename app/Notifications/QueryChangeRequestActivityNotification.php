<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * Language-neutral database notification. Text, query contents and comments
 * are deliberately resolved from authorized relations instead of persisted in
 * the notification payload.
 */
class QueryChangeRequestActivityNotification extends Notification
{
    use Queueable;

    public const string CREATED = 'query_change_request_created';

    public const string COMMENTED = 'query_change_request_commented';

    public const string MENTIONED = 'query_change_request_mentioned';

    public const string STATUS_CHANGED = 'query_change_request_status_changed';

    public function __construct(
        public readonly string $event,
        public readonly int $changeRequestId,
        public readonly int $queryId,
        public readonly int $actorUserId,
        public readonly ?int $commentId = null,
    ) {
        if (! in_array($event, [self::CREATED, self::COMMENTED, self::MENTIONED, self::STATUS_CHANGED], true)) {
            throw new InvalidArgumentException("Unknown query change-request event [{$event}].");
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event;
    }

    /** @return array<string, int> */
    public function toDatabase(object $notifiable): array
    {
        return array_filter([
            'change_request_id' => $this->changeRequestId,
            'query_id' => $this->queryId,
            'actor_user_id' => $this->actorUserId,
            'comment_id' => $this->commentId,
        ], static fn (?int $value): bool => $value !== null);
    }
}
