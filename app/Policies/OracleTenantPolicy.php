<?php

namespace App\Policies;

use App\Models\OracleTenant;
use App\Models\User;

class OracleTenantPolicy
{
    public function view(User $user, OracleTenant $tenant): bool
    {
        return $tenant->user_id === $user->id;
    }

    public function update(User $user, OracleTenant $tenant): bool
    {
        return $tenant->user_id === $user->id;
    }

    public function delete(User $user, OracleTenant $tenant): bool
    {
        return $tenant->user_id === $user->id;
    }
}
