<?php

namespace App\Policies;

use App\Models\SemanticResource;
use App\Models\User;

class SemanticResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SemanticResource $semanticResource): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SemanticResource $semanticResource): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageFields(User $user, SemanticResource $semanticResource): bool
    {
        return $user->isSuperAdmin();
    }

    public function manageRelations(User $user, SemanticResource $semanticResource): bool
    {
        return $user->isSuperAdmin();
    }
}
