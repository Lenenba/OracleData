<?php

namespace App\Policies;

use App\Models\AuthConnection;
use App\Models\User;

class AuthConnectionPolicy
{
    public function view(User $user, AuthConnection $connection): bool
    {
        return $connection->user_id === $user->id;
    }

    public function update(User $user, AuthConnection $connection): bool
    {
        return $connection->user_id === $user->id;
    }

    public function delete(User $user, AuthConnection $connection): bool
    {
        return $connection->user_id === $user->id;
    }
}
