<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/** Internal response notification containing identifiers only. */
class QueryShareInvitationResponseNotification extends Notification
{
    use Queueable;

    public const string ACCEPTED = 'accepted';

    public const string DECLINED = 'declined';

    public function __construct(
        public readonly int $shareId,
        public readonly int $queryId,
        public readonly int $respondedByUserId,
        public readonly string $response,
    ) {
        if (! in_array($response, [self::ACCEPTED, self::DECLINED], true)) {
            throw new InvalidArgumentException("Unknown invitation response [{$response}].");
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return "query_share_invitation_{$this->response}";
    }

    /** @return array{share_id: int, query_id: int, responded_by_user_id: int} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'share_id' => $this->shareId,
            'query_id' => $this->queryId,
            'responded_by_user_id' => $this->respondedByUserId,
        ];
    }
}
