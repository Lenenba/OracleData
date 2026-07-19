<?php

namespace App\Policies;

use App\Models\SemanticGlossaryTerm;
use App\Models\User;

class SemanticGlossaryTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SemanticGlossaryTerm $semanticGlossaryTerm): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SemanticGlossaryTerm $semanticGlossaryTerm): bool
    {
        return $user->isSuperAdmin();
    }
}
