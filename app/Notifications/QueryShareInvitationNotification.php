<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal, language-neutral notification for an authenticated recipient.
 *
 * It deliberately uses the synchronous database channel so the notification
 * and invitation share the same transaction. External e-mail delivery remains
 * a later, after-commit queued workflow.
 */
class QueryShareInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $shareId,
        public readonly int $queryId,
        public readonly int $invitedByUserId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'query_share_invitation';
    }

    /** @return array{share_id: int, query_id: int, invited_by_user_id: int} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'share_id' => $this->shareId,
            'query_id' => $this->queryId,
            'invited_by_user_id' => $this->invitedByUserId,
        ];
    }
}
