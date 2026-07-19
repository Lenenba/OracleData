<?php

namespace App\Services;

use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\QueryUserShare;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QueryShareLifecycleService
{
    public function lockManageableQuery(User $actor, Query $query): Query
    {
        $lockedQuery = Query::query()->lockForUpdate()->findOrFail($query->id);
        Gate::forUser($actor)->authorize('manageSharing', $lockedQuery);

        return $lockedQuery;
    }

    public function assertDelegationWithinActorGrant(
        User $actor,
        Query $query,
        QuerySharePermission $permission,
        mixed $expiresAt,
    ): void {
        if ($actor->id === $query->user_id) {
            return;
        }

        $actorGrant = $query->activeShareFor($actor);

        if ($actorGrant === null || $actorGrant->permission !== QuerySharePermission::MANAGE) {
            abort(403);
        }

        if ($permission === QuerySharePermission::MANAGE) {
            throw ValidationException::withMessages([
                'permission' => __('Seul le propriétaire peut déléguer la gestion du partage.'),
            ]);
        }

        if ($actorGrant->expires_at === null) {
            return;
        }

        if ($expiresAt === null || $expiresAt === '') {
            throw ValidationException::withMessages([
                'expires_at' => __('L’expiration doit être antérieure ou égale à celle de votre propre accès.'),
            ]);
        }

        $delegatedExpiry = $expiresAt instanceof CarbonInterface
            ? CarbonImmutable::instance($expiresAt)
            : CarbonImmutable::parse((string) $expiresAt);

        if ($delegatedExpiry->gt($actorGrant->expires_at)) {
            throw ValidationException::withMessages([
                'expires_at' => __('L’expiration doit être antérieure ou égale à celle de votre propre accès.'),
            ]);
        }
    }

    /**
     * Move a private query to restricted when its first current grant is
     * created. Any inconsistent residual grants are revoked first.
     */
    public function restrictForNewShare(
        User $actor,
        Query $query,
        AuditRecorder $audit,
        string $reason,
    ): void {
        if ($query->access_level !== QueryAccessLevel::PRIVATE) {
            return;
        }

        $revoked = $this->revokeActiveGrants(
            $actor,
            $query,
            $audit,
            'private_residual_grant',
            includePendingInvitations: false,
        );
        $query->forceFill(['access_level' => QueryAccessLevel::RESTRICTED])->save();
        $audit->record($actor, 'query.access_level_updated', $query, [
            'before' => QueryAccessLevel::PRIVATE->value,
            'after' => QueryAccessLevel::RESTRICTED->value,
            'reason' => $reason,
            'revoked_share_count' => $revoked['total'],
            'revoked_user_share_count' => $revoked['users'],
            'revoked_group_share_count' => $revoked['groups'],
            'cancelled_invitation_count' => $revoked['invitations'],
        ]);
    }

    /**
     * Revoke accepted grants and, for closure workflows, cancel invitations
     * that could otherwise be accepted after a query becomes private.
     *
     * @return array{users: int, groups: int, invitations: int, total: int}
     */
    public function revokeActiveGrants(
        User $actor,
        Query $query,
        AuditRecorder $audit,
        string $reason,
        bool $includePendingInvitations = true,
    ): array {
        $userSharesQuery = QueryUserShare::query()
            ->where('query_id', $query->id)
            ->when(
                $includePendingInvitations,
                fn ($shares) => $shares->current(),
                fn ($shares) => $shares->active(),
            );
        $userShares = $userSharesQuery->lockForUpdate()->get();

        $cancelledInvitationCount = 0;

        foreach ($userShares as $share) {
            if ($share->status === QueryUserShare::STATUS_PENDING) {
                $share->cancel();
                $cancelledInvitationCount++;
                $audit->record($actor, 'query.share_invitation_cancelled', $query, [
                    'share_id' => $share->id,
                    'recipient_user_id' => $share->user_id,
                    'reason' => $reason,
                ]);
            } else {
                $share->revoke();
                $audit->record($actor, 'query.share_revoked', $query, [
                    'share_id' => $share->id,
                    'recipient_user_id' => $share->user_id,
                    'permission' => $share->permission->value,
                    'reason' => $reason,
                ]);
            }
        }

        $groupShares = QueryGroupShare::query()
            ->where('query_id', $query->id)
            ->active()
            ->lockForUpdate()
            ->get();

        foreach ($groupShares as $share) {
            $share->revoke();
            $audit->record($actor, 'query.group_share_revoked', $query, [
                'group_share_id' => $share->id,
                'group_id' => $share->group_id,
                'permission' => $share->permission->value,
                'reason' => $reason,
            ]);
        }

        $revokedUserGrantCount = $userShares->count() - $cancelledInvitationCount;

        return [
            'users' => $revokedUserGrantCount,
            'groups' => $groupShares->count(),
            'invitations' => $cancelledInvitationCount,
            'total' => $userShares->count() + $groupShares->count(),
        ];
    }
}
