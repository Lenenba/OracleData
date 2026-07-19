<?php

namespace App\Enums;

enum GroupRole: string
{
    case OWNER = 'owner';

    case MANAGER = 'manager';

    case MEMBER = 'member';

    public function canManageMembers(): bool
    {
        return $this !== self::MEMBER;
    }
}
