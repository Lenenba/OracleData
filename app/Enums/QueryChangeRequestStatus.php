<?php

namespace App\Enums;

enum QueryChangeRequestStatus: string
{
    case PENDING = 'pending';

    case ACCEPTED = 'accepted';

    case REJECTED = 'rejected';

    case COMPLETED = 'completed';

    case CANCELLED = 'cancelled';

    public function isCommentable(): bool
    {
        return $this === self::PENDING || $this === self::ACCEPTED;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::REJECTED, self::COMPLETED, self::CANCELLED], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING => in_array($target, [self::ACCEPTED, self::REJECTED, self::CANCELLED], true),
            self::ACCEPTED => in_array($target, [self::COMPLETED, self::CANCELLED], true),
            self::REJECTED, self::COMPLETED, self::CANCELLED => false,
        };
    }
}
