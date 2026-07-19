<?php

namespace App\Policies;

use App\Enums\QuerySharePermission;
use App\Models\Query;
use App\Models\User;

class QueryPolicy
{
    public function view(User $user, Query $query): bool
    {
        return $query->allows($user, QuerySharePermission::VIEW);
    }

    public function execute(User $user, Query $query): bool
    {
        return $query->allows($user, QuerySharePermission::EXECUTE);
    }

    public function clone(User $user, Query $query): bool
    {
        return $query->allows($user, QuerySharePermission::CLONE);
    }

    public function manageSharing(User $user, Query $query): bool
    {
        return $query->allows($user, QuerySharePermission::MANAGE);
    }

    /**
     * Owners edit directly; current readers use the governed change-request
     * workflow without receiving any additional permission on the definition.
     */
    public function requestChange(User $user, Query $query): bool
    {
        return $user->id !== $query->user_id
            && $query->allows($user, QuerySharePermission::VIEW);
    }

    /**
     * Changing the global access level can remove individual grants or grant
     * organization-wide access, so it remains an owner-only governance action.
     */
    public function changeAccessLevel(User $user, Query $query): bool
    {
        return $user->id === $query->user_id;
    }

    /**
     * Editing and deletion remain owner-only. A delegated MANAGE grant allows
     * sharing administration without permitting destructive query changes.
     */
    public function update(User $user, Query $query): bool
    {
        return $user->id === $query->user_id;
    }

    public function delete(User $user, Query $query): bool
    {
        return $user->id === $query->user_id;
    }
}
