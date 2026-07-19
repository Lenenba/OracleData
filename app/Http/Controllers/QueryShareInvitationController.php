<?php

namespace App\Http\Controllers;

use App\Enums\QuerySharePermission;
use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use App\Notifications\QueryShareInvitationNotification;
use App\Notifications\QueryShareInvitationResponseNotification;
use App\Services\AuditRecorder;
use App\Services\QueryShareLifecycleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class QueryShareInvitationController extends Controller
{
    /**
     * Invite an existing platform user without granting access before consent.
     */
    public function store(
        Request $request,
        Query $query,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('manageSharing', $query);

        /** @var array{user_id: int, permission: string, expires_at?: string|null, respond_by?: string|null} $validated */
        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$query->user_id]),
            ],
            'permission' => ['required', Rule::enum(QuerySharePermission::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'respond_by' => ['nullable', 'date', 'after:now'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $expiresAt = $this->date($validated['expires_at'] ?? null);
        $requestedRespondBy = $this->date($validated['respond_by'] ?? null);

        if ($expiresAt !== null && $requestedRespondBy?->gt($expiresAt)) {
            throw ValidationException::withMessages([
                'respond_by' => __('La date limite de réponse doit précéder l’expiration de l’accès.'),
            ]);
        }

        $respondBy = $this->responseDeadline(
            $requestedRespondBy,
            $expiresAt,
        );

        $share = DB::transaction(function () use (
            $actor,
            $audit,
            $expiresAt,
            $lifecycle,
            $query,
            $respondBy,
            $validated,
        ): QueryUserShare {
            $lockedQuery = $lifecycle->lockManageableQuery($actor, $query);
            $recipient = User::query()
                ->whereKey((int) $validated['user_id'])
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($recipient->id === $lockedQuery->user_id, Response::HTTP_UNPROCESSABLE_ENTITY);

            $permission = QuerySharePermission::from($validated['permission']);
            $lifecycle->assertDelegationWithinActorGrant(
                $actor,
                $lockedQuery,
                $permission,
                $expiresAt,
            );

            $alreadyCurrent = QueryUserShare::query()
                ->where('query_id', $lockedQuery->id)
                ->where('user_id', $recipient->id)
                ->current()
                ->lockForUpdate()
                ->exists();
            abort_if(
                $alreadyCurrent,
                Response::HTTP_CONFLICT,
                __('Cette personne dispose déjà d’un accès ou d’une invitation en attente.'),
            );

            $share = new QueryUserShare;
            $share->forceFill([
                'query_id' => $lockedQuery->id,
                'shared_by_user_id' => $actor->id,
                'user_id' => $recipient->id,
                'permission' => $permission,
                'status' => QueryUserShare::STATUS_PENDING,
                'expires_at' => $expiresAt,
                'respond_by' => $respondBy,
                'accepted_at' => null,
                'declined_at' => null,
                'cancelled_at' => null,
                'revoked_at' => null,
            ])->save();

            $audit->record($actor, 'query.share_invited', $lockedQuery, [
                'share_id' => $share->id,
                'recipient_user_id' => $recipient->id,
                'permission' => $permission->value,
                'expires_at' => $this->isoDate($expiresAt),
                'respond_by' => $this->isoDate($respondBy),
            ]);
            $recipient->notify(new QueryShareInvitationNotification(
                $share->id,
                $lockedQuery->id,
                $actor->id,
            ));

            return $share->refresh();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'invitation' => $this->invitationPayload($share),
            ], Response::HTTP_CREATED);
        }

        return back()->with('status', 'query-share-invited');
    }

    /**
     * Accept an invitation owned by the authenticated recipient.
     */
    public function accept(
        Request $request,
        int $queryUserShare,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse|RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $ownedInvitation = QueryUserShare::query()
            ->select(['id', 'query_id'])
            ->whereKey($queryUserShare)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $result = DB::transaction(function () use (
            $actor,
            $audit,
            $lifecycle,
            $ownedInvitation,
        ): array {
            $query = Query::query()
                ->lockForUpdate()
                ->findOrFail($ownedInvitation->query_id);
            $share = QueryUserShare::query()
                ->whereKey($ownedInvitation->id)
                ->where('query_id', $query->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($share->status === QueryUserShare::STATUS_ACCEPTED) {
                $this->markInvitationNotificationRead($actor, $share);

                return ['state' => 'accepted', 'query_id' => $query->id, 'share' => $share];
            }

            if (! $share->isPending()) {
                return ['state' => 'unavailable', 'query_id' => $query->id, 'share' => $share];
            }

            if (! $this->inviterStillAuthorized($query, $share)) {
                $share->cancel();
                $audit->record($actor, 'query.share_invitation_cancelled', $query, [
                    'share_id' => $share->id,
                    'recipient_user_id' => $share->user_id,
                    'reason' => 'inviter_authority_lost',
                ]);
                $this->markInvitationNotificationRead($actor, $share);

                return ['state' => 'authority_lost', 'query_id' => $query->id, 'share' => $share];
            }

            $hasOtherActiveGrant = QueryUserShare::query()
                ->where('query_id', $query->id)
                ->where('user_id', $actor->id)
                ->whereKeyNot($share->id)
                ->active()
                ->lockForUpdate()
                ->exists();

            if ($hasOtherActiveGrant) {
                return ['state' => 'duplicate', 'query_id' => $query->id, 'share' => $share];
            }

            $lifecycle->restrictForNewShare(
                $actor,
                $query,
                $audit,
                'invitation_accepted',
            );
            $share->accept();
            $audit->record($actor, 'query.share_invitation_accepted', $query, [
                'share_id' => $share->id,
                'recipient_user_id' => $actor->id,
                'permission' => $share->permission->value,
            ]);
            $this->notifyInvitationResponse(
                $share,
                $actor,
                QueryShareInvitationResponseNotification::ACCEPTED,
            );
            $this->markInvitationNotificationRead($actor, $share);

            return ['state' => 'accepted', 'query_id' => $query->id, 'share' => $share->refresh()];
        });

        if ($result['state'] !== 'accepted') {
            return $this->conflictResponse($request, match ($result['state']) {
                'authority_lost' => __('Cette invitation a été annulée car l’émetteur ne peut plus partager la requête.'),
                'duplicate' => __('Vous disposez déjà d’un accès actif à cette requête.'),
                default => __('Cette invitation n’est plus disponible.'),
            });
        }

        if ($request->expectsJson()) {
            /** @var QueryUserShare $acceptedShare */
            $acceptedShare = $result['share'];

            return response()->json([
                'share' => $this->invitationPayload($acceptedShare),
                'query_id' => $result['query_id'],
            ]);
        }

        return to_route('queries.show', $result['query_id'])
            ->with('status', 'query-share-invitation-accepted');
    }

    /**
     * Decline an invitation without ever opening access to the query.
     */
    public function decline(
        Request $request,
        int $queryUserShare,
        AuditRecorder $audit,
    ): Response|RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $ownedInvitation = QueryUserShare::query()
            ->select(['id', 'query_id'])
            ->whereKey($queryUserShare)
            ->where('user_id', $actor->id)
            ->firstOrFail();

        $state = DB::transaction(function () use ($actor, $audit, $ownedInvitation): string {
            $query = Query::query()
                ->lockForUpdate()
                ->findOrFail($ownedInvitation->query_id);
            $share = QueryUserShare::query()
                ->whereKey($ownedInvitation->id)
                ->where('query_id', $query->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($share->status === QueryUserShare::STATUS_DECLINED) {
                $this->markInvitationNotificationRead($actor, $share);

                return 'declined';
            }

            if (! $share->isPending()) {
                return 'unavailable';
            }

            $share->decline();
            $audit->record($actor, 'query.share_invitation_declined', $query, [
                'share_id' => $share->id,
                'recipient_user_id' => $actor->id,
            ]);
            $this->notifyInvitationResponse(
                $share,
                $actor,
                QueryShareInvitationResponseNotification::DECLINED,
            );
            $this->markInvitationNotificationRead($actor, $share);

            return 'declined';
        });

        if ($state !== 'declined') {
            return $this->conflictResponse($request, __('Cette invitation n’est plus disponible.'));
        }

        return $request->expectsJson()
            ? response()->noContent()
            : to_route('notifications.index')->with('status', 'query-share-invitation-declined');
    }

    private function inviterStillAuthorized(Query $query, QueryUserShare $invitation): bool
    {
        if ($invitation->shared_by_user_id === $query->user_id) {
            return true;
        }

        if (
            $invitation->shared_by_user_id === null
            || $invitation->permission === QuerySharePermission::MANAGE
        ) {
            return false;
        }

        $inviter = User::query()->find($invitation->shared_by_user_id);
        $grant = $inviter === null ? null : $query->activeShareFor($inviter);

        if ($grant?->permission !== QuerySharePermission::MANAGE) {
            return false;
        }

        return $grant->expires_at === null
            || ($invitation->expires_at !== null && $invitation->expires_at->lte($grant->expires_at));
    }

    private function markInvitationNotificationRead(User $recipient, QueryUserShare $share): void
    {
        $recipient->unreadNotifications()
            ->where('type', 'query_share_invitation')
            ->where('data->share_id', $share->id)
            ->update(['read_at' => now()]);
    }

    private function notifyInvitationResponse(
        QueryUserShare $share,
        User $actor,
        string $response,
    ): void {
        if ($share->shared_by_user_id === null || $share->shared_by_user_id === $actor->id) {
            return;
        }

        User::query()->find($share->shared_by_user_id)?->notify(
            new QueryShareInvitationResponseNotification(
                $share->id,
                $share->query_id,
                $actor->id,
                $response,
            ),
        );
    }

    private function responseDeadline(?CarbonImmutable $value, ?CarbonImmutable $expiresAt): CarbonImmutable
    {
        $deadline = $value ?? CarbonImmutable::now()->addWeek();

        return $expiresAt !== null && $expiresAt->lt($deadline)
            ? $expiresAt
            : $deadline;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === ''
            ? null
            : CarbonImmutable::parse((string) $value);
    }

    /** @return array<string, mixed> */
    private function invitationPayload(QueryUserShare $share): array
    {
        return [
            'id' => $share->id,
            'status' => $share->isPending() ? 'pending' : $share->status,
            'permission' => $share->permission->value,
            'expires_at' => $this->isoDate($share->expires_at),
            'respond_by' => $this->isoDate($share->respond_by),
            'accepted_at' => $this->isoDate($share->accepted_at),
            'declined_at' => $this->isoDate($share->declined_at),
            'cancelled_at' => $this->isoDate($share->cancelled_at),
            'revoked_at' => $this->isoDate($share->revoked_at),
            'is_active' => $share->isActive(),
            'is_pending' => $share->isPending(),
        ];
    }

    private function isoDate(?CarbonInterface $value): ?string
    {
        return $value?->toISOString();
    }

    private function conflictResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_CONFLICT);
        }

        return back()->withErrors(['invitation' => $message]);
    }
}
