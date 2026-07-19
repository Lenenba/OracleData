<?php

namespace App\Policies;

use App\Enums\QuerySharePermission;
use App\Models\QueryChangeRequest;
use App\Models\User;

class QueryChangeRequestPolicy
{
    /** Every current query reader may follow its collaborative threads. */
    public function view(User $user, QueryChangeRequest $changeRequest): bool
    {
        return $changeRequest->subjectQuery->allows($user, QuerySharePermission::VIEW);
    }

    public function comment(User $user, QueryChangeRequest $changeRequest): bool
    {
        return $changeRequest->status->isCommentable()
            && $this->view($user, $changeRequest);
    }

    public function transition(User $user, QueryChangeRequest $changeRequest): bool
    {
        return $user->id === $changeRequest->subjectQuery->user_id
            || $user->id === $changeRequest->requested_by_user_id;
    }
}
