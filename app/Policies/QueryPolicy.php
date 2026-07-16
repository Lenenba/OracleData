<?php

namespace App\Policies;

use App\Models\Query;
use App\Models\User;

class QueryPolicy
{
    /**
     * The owner — or anyone, when the query is shared — may view and run it.
     */
    public function view(User $user, Query $query): bool
    {
        return $query->visibility === 'shared' || $user->id === $query->user_id;
    }

    /**
     * Only the owner may update or delete the query.
     */
    public function update(User $user, Query $query): bool
    {
        return $user->id === $query->user_id;
    }
}
