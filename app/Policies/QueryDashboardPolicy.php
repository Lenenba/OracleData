<?php

namespace App\Policies;

use App\Models\QueryDashboard;
use App\Models\User;

/**
 * Lot 10B — all dashboards belong strictly to the creating user.
 */
class QueryDashboardPolicy
{
    public function view(User $user, QueryDashboard $dashboard): bool
    {
        return $dashboard->user_id === $user->id;
    }

    public function update(User $user, QueryDashboard $dashboard): bool
    {
        return $dashboard->user_id === $user->id;
    }

    public function delete(User $user, QueryDashboard $dashboard): bool
    {
        return $dashboard->user_id === $user->id;
    }
}
