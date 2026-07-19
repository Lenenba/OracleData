<?php

namespace App\Http\Controllers;

use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use App\Models\Group;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\QueryUserShare;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\QueryShareLifecycleService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class QueryShareController extends Controller
{
    /**
     * Show the direct-sharing administration page to the owner or a delegated
     * manager. Candidate and history pages use independent query parameters.
     */
    public function index(Request $request, Query $query): JsonResponse|InertiaResponse
    {
        Gate::authorize('manageSharing', $query);
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{recipient_type?: string|null, history_recipient_type?: string|null, search?: string|null, per_page?: int, candidate_page?: int, history_page?: int} $validated */
        $validated = $request->validate([
            'recipient_type' => ['nullable', Rule::in(['user', 'group'])],
            'history_recipient_type' => ['nullable', Rule::in(['user', 'group'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'candidate_page' => ['nullable', 'integer', 'min:1'],
            'history_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $recipientType = (string) ($validated['recipient_type'] ?? 'user');
        $historyRecipientType = (string) ($validated['history_recipient_type'] ?? 'user');
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        if ($recipientType === 'group') {
            $activeRecipientIds = QueryGroupShare::query()
                ->select('group_id')
                ->where('query_id', $query->id)
                ->active();
            $candidates = Group::query()
                ->forUser($actor)
                ->whereNotIn('id', $activeRecipientIds)
                ->when($search !== '', fn (Builder $groups) => $groups
                    ->where(fn (Builder $matching) => $matching
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")))
                ->withCount('members')
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'candidate_page');
            $candidateMeta = $this->paginationMeta($candidates);
        } else {
            $activeRecipientIds = QueryUserShare::query()
                ->select('user_id')
                ->where('query_id', $query->id)
                ->current();
            $candidates = User::query()
                ->select(['id', 'name', 'email'])
                ->whereKeyNot($query->user_id)
                ->whereNotIn('id', $activeRecipientIds)
                ->when($search !== '', fn (Builder $users) => $users
                    ->where(fn (Builder $matching) => $matching
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'candidate_page');
            $candidateMeta = $this->paginationMeta($candidates);
        }

        $activeUserShares = QueryUserShare::query()
            ->where('query_id', $query->id)
            ->active()
            ->orderBy('user_id')
            ->get();
        $pendingInvitations = QueryUserShare::query()
            ->where('query_id', $query->id)
            ->pending()
            ->orderBy('respond_by')
            ->orderBy('user_id')
            ->get();
        $activeGroupShares = QueryGroupShare::query()
            ->where('query_id', $query->id)
            ->active()
            ->with(['group' => fn ($groups) => $groups->withCount('members')])
            ->orderBy('group_id')
            ->get();

        if ($historyRecipientType === 'group') {
            $shareHistory = QueryGroupShare::query()
                ->where('query_id', $query->id)
                ->where(fn (Builder $history) => $history
                    ->where('status', QueryGroupShare::STATUS_REVOKED)
                    ->orWhere(fn (Builder $expired) => $expired
                        ->where('status', QueryGroupShare::STATUS_ACCEPTED)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())))
                ->with(['group' => fn ($groups) => $groups
                    ->withTrashed()
                    ->withCount('members')])
                ->orderByDesc('revoked_at')
                ->orderByDesc('expires_at')
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], 'history_page');
            $historyMeta = $this->paginationMeta($shareHistory);
            $historyUserShares = collect();
            $historyGroupShares = $shareHistory->getCollection();
        } else {
            $shareHistory = QueryUserShare::query()
                ->where('query_id', $query->id)
                ->where(fn (Builder $history) => $history
                    ->where('status', QueryUserShare::STATUS_REVOKED)
                    ->orWhere('status', QueryUserShare::STATUS_DECLINED)
                    ->orWhere('status', QueryUserShare::STATUS_CANCELLED)
                    ->orWhere(fn (Builder $expired) => $expired
                        ->where('status', QueryUserShare::STATUS_ACCEPTED)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now()))
                    ->orWhere(fn (Builder $expiredInvitation) => $expiredInvitation
                        ->where('status', QueryUserShare::STATUS_PENDING)
                        ->where(fn (Builder $deadline) => $deadline
                            ->where('respond_by', '<=', now())
                            ->orWhere('expires_at', '<=', now()))))
                ->orderByDesc('revoked_at')
                ->orderByDesc('cancelled_at')
                ->orderByDesc('declined_at')
                ->orderByDesc('expires_at')
                ->orderByDesc('id')
                ->paginate($perPage, ['*'], 'history_page');
            $historyMeta = $this->paginationMeta($shareHistory);
            $historyUserShares = $shareHistory->getCollection();
            $historyGroupShares = collect();
        }

        $shareUsers = $this->shareUsers(
            $activeUserShares
                ->concat($pendingInvitations)
                ->concat($historyUserShares),
            $activeGroupShares->concat($historyGroupShares),
        );
        $owner = User::query()->select(['id', 'name'])->findOrFail($query->user_id);
        $activeShares = $activeUserShares
            ->map(fn (QueryUserShare $share): array => $this->sharePayload($share, $shareUsers, $actor, $query))
            ->concat($activeGroupShares
                ->map(fn (QueryGroupShare $share): array => $this->groupSharePayload($share, $shareUsers)))
            ->values()
            ->all();
        $payload = [
            'query' => [
                'id' => $query->id,
                'name' => $query->name,
                'access_level' => $query->access_level->value,
                'owner' => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                ],
                'can_change_access_level' => $actor->can('changeAccessLevel', $query),
                'delegation_expires_at' => $actor->id === $query->user_id
                    ? null
                    : $this->isoDate($query->activeShareFor($actor)?->expires_at),
            ],
            'permissions' => array_map(
                fn (QuerySharePermission $permission): string => $permission->value,
                $actor->id === $query->user_id
                    ? QuerySharePermission::cases()
                    : array_filter(
                        QuerySharePermission::cases(),
                        fn (QuerySharePermission $permission): bool => $permission !== QuerySharePermission::MANAGE,
                    ),
            ),
            'groupPermissions' => [
                QuerySharePermission::VIEW->value,
                QuerySharePermission::EXECUTE->value,
                QuerySharePermission::CLONE->value,
            ],
            'accessLevels' => array_map(
                fn (QueryAccessLevel $level): string => $level->value,
                QueryAccessLevel::cases(),
            ),
            'recipientType' => $recipientType,
            'historyRecipientType' => $historyRecipientType,
            'search' => $search,
            'candidates' => [
                'data' => $candidates->getCollection()
                    ->map(fn (User|Group $candidate): array => $candidate instanceof User
                        ? $this->candidatePayload($candidate)
                        : $this->groupCandidatePayload($candidate))
                    ->values()
                    ->all(),
                'meta' => $candidateMeta,
            ],
            'activeShares' => $activeShares,
            'pendingInvitations' => $pendingInvitations
                ->map(fn (QueryUserShare $share): array => $this->sharePayload($share, $shareUsers, $actor, $query))
                ->values()
                ->all(),
            'shareHistory' => [
                'data' => $shareHistory->getCollection()
                    ->map(fn (QueryUserShare|QueryGroupShare $share): array => $share instanceof QueryUserShare
                        ? $this->sharePayload($share, $shareUsers, $actor, $query)
                        : $this->groupSharePayload($share, $shareUsers))
                    ->values()
                    ->all(),
                'meta' => $historyMeta,
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('queries/sharing', $payload);
    }

    /**
     * Create a new direct-grant lifecycle. The first grant also moves a
     * private query to restricted access in the same transaction.
     */
    public function store(
        Request $request,
        Query $query,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('manageSharing', $query);

        /** @var array{user_id: int, permission: string, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([$query->user_id]),
            ],
            'permission' => ['required', Rule::enum(QuerySharePermission::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        $share = DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $validated): QueryUserShare {
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
                $validated['expires_at'] ?? null,
            );
            $lifecycle->restrictForNewShare(
                $actor,
                $lockedQuery,
                $audit,
                'first_direct_share',
            );

            $alreadyActive = QueryUserShare::query()
                ->where('query_id', $lockedQuery->id)
                ->where('user_id', $recipient->id)
                ->current()
                ->lockForUpdate()
                ->first() !== null;

            abort_if(
                $alreadyActive,
                Response::HTTP_CONFLICT,
                __('Cette personne dispose déjà d’un partage actif.'),
            );

            $share = new QueryUserShare;
            $share->forceFill([
                'query_id' => $lockedQuery->id,
                'shared_by_user_id' => $actor->id,
                'user_id' => $recipient->id,
                'permission' => $permission,
                'status' => QueryUserShare::STATUS_ACCEPTED,
                'expires_at' => $validated['expires_at'] ?? null,
                'accepted_at' => now(),
                'revoked_at' => null,
            ])->save();

            $audit->record($actor, 'query.shared', $lockedQuery, [
                'share_id' => $share->id,
                'recipient_user_id' => $recipient->id,
                'permission' => $share->permission->value,
                'expires_at' => $this->isoDate($share->expires_at),
            ]);

            return $share->refresh();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'share' => $this->sharePayload(
                    $share,
                    $this->shareUsers(collect([$share])),
                    $actor,
                    $query,
                ),
            ], Response::HTTP_CREATED);
        }

        return back()->with('status', 'query-share-created');
    }

    /**
     * Update the permission and/or expiry of a non-revoked direct grant.
     */
    public function update(
        Request $request,
        Query $query,
        QueryUserShare $queryUserShare,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('manageSharing', $query);
        $this->assertShareBelongsToQuery($query, $queryUserShare);

        if (! array_key_exists('permission', $request->all()) && ! array_key_exists('expires_at', $request->all())) {
            throw ValidationException::withMessages([
                'permission' => __('Une permission ou une expiration doit être fournie.'),
            ]);
        }

        /** @var array{permission?: string, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'permission' => ['sometimes', 'required', Rule::enum(QuerySharePermission::class)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        $share = DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $queryUserShare, $validated): QueryUserShare {
            $lockedQuery = $lifecycle->lockManageableQuery($actor, $query);
            $share = QueryUserShare::query()->lockForUpdate()->findOrFail($queryUserShare->id);
            $this->assertShareBelongsToQuery($lockedQuery, $share);
            $this->assertActorMayMutateShare($actor, $lockedQuery, $share);

            abort_unless(
                $share->isActive(),
                Response::HTTP_CONFLICT,
                __('Un partage inactif doit être recréé.'),
            );

            $before = [
                'permission' => $share->permission->value,
                'expires_at' => $this->isoDate($share->expires_at),
            ];
            $changes = [];

            if (array_key_exists('permission', $validated)) {
                $changes['permission'] = $validated['permission'];
            }

            if (array_key_exists('expires_at', $validated)) {
                $changes['expires_at'] = $validated['expires_at'];
            }

            $nextPermission = array_key_exists('permission', $validated)
                ? QuerySharePermission::from($validated['permission'])
                : $share->permission;
            $nextExpiresAt = array_key_exists('expires_at', $validated)
                ? $validated['expires_at']
                : $share->expires_at;
            $lifecycle->assertDelegationWithinActorGrant(
                $actor,
                $lockedQuery,
                $nextPermission,
                $nextExpiresAt,
            );

            $share->forceFill($changes)->save();
            $share->refresh();

            $audit->record($actor, 'query.share_updated', $lockedQuery, [
                'share_id' => $share->id,
                'before' => $before,
                'after' => [
                    'permission' => $share->permission->value,
                    'expires_at' => $this->isoDate($share->expires_at),
                ],
            ]);

            return $share;
        });

        if ($request->expectsJson()) {
            return response()->json([
                'share' => $this->sharePayload(
                    $share,
                    $this->shareUsers(collect([$share])),
                    $actor,
                    $query,
                ),
            ]);
        }

        return back()->with('status', 'query-share-updated');
    }

    /**
     * Revoke a direct grant while retaining its historical row.
     */
    public function destroy(
        Request $request,
        Query $query,
        QueryUserShare $queryUserShare,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): Response {
        Gate::authorize('manageSharing', $query);
        $this->assertShareBelongsToQuery($query, $queryUserShare);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $queryUserShare): void {
            $lockedQuery = $lifecycle->lockManageableQuery($actor, $query);
            $share = QueryUserShare::query()->lockForUpdate()->findOrFail($queryUserShare->id);
            $this->assertShareBelongsToQuery($lockedQuery, $share);
            $this->assertActorMayMutateShare($actor, $lockedQuery, $share);

            if ($share->status === QueryUserShare::STATUS_PENDING) {
                $share->cancel();
                $audit->record($actor, 'query.share_invitation_cancelled', $lockedQuery, [
                    'share_id' => $share->id,
                    'recipient_user_id' => $share->user_id,
                    'reason' => 'manual_cancellation',
                ]);

                return;
            }

            if (! $share->isActive()) {
                return;
            }

            $share->revoke();
            $audit->record($actor, 'query.share_revoked', $lockedQuery, [
                'share_id' => $share->id,
                'recipient_user_id' => $share->user_id,
                'permission' => $share->permission->value,
                'reason' => 'direct_revocation',
            ]);
        });

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back()->with('status', 'query-share-revoked');
    }

    /**
     * Change the declared access level. Returning to private atomically
     * revokes every currently active direct grant.
     */
    public function updateAccessLevel(
        Request $request,
        Query $query,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('changeAccessLevel', $query);

        /** @var array{access_level: string} $validated */
        $validated = $request->validate([
            'access_level' => ['required', Rule::enum(QueryAccessLevel::class)],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $target = QueryAccessLevel::from($validated['access_level']);

        $updatedQuery = DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $target): Query {
            $lockedQuery = Query::query()->lockForUpdate()->findOrFail($query->id);
            Gate::forUser($actor)->authorize('changeAccessLevel', $lockedQuery);
            $before = $lockedQuery->access_level;

            if ($before === $target && $target !== QueryAccessLevel::PRIVATE) {
                return $lockedQuery;
            }

            $revoked = ['users' => 0, 'groups' => 0, 'invitations' => 0, 'total' => 0];

            if ($target === QueryAccessLevel::PRIVATE) {
                $revoked = $lifecycle->revokeActiveGrants(
                    $actor,
                    $lockedQuery,
                    $audit,
                    'access_level_private',
                );
            }

            if ($before !== $target) {
                $lockedQuery->forceFill(['access_level' => $target])->save();
            }

            if ($before === $target && $revoked['total'] === 0) {
                return $lockedQuery;
            }

            $audit->record($actor, 'query.access_level_updated', $lockedQuery, [
                'before' => $before->value,
                'after' => $target->value,
                'revoked_share_count' => $revoked['total'],
                'revoked_user_share_count' => $revoked['users'],
                'revoked_group_share_count' => $revoked['groups'],
                'cancelled_invitation_count' => $revoked['invitations'],
            ]);

            return $lockedQuery->refresh();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'access_level' => $updatedQuery->access_level->value,
                'query' => [
                    'id' => $updatedQuery->id,
                    'access_level' => $updatedQuery->access_level->value,
                ],
            ]);
        }

        return back()->with('status', 'query-access-level-updated');
    }

    private function assertShareBelongsToQuery(Query $query, QueryUserShare $share): void
    {
        abort_unless($share->query_id === $query->id, Response::HTTP_NOT_FOUND);
    }

    private function actorMayMutateShare(User $actor, Query $query, QueryUserShare $share): bool
    {
        if ($actor->id === $query->user_id) {
            return true;
        }

        if ($share->status === QueryUserShare::STATUS_PENDING) {
            return $share->shared_by_user_id === $actor->id;
        }

        return $share->user_id !== $actor->id
            && $share->permission !== QuerySharePermission::MANAGE;
    }

    private function assertActorMayMutateShare(User $actor, Query $query, QueryUserShare $share): void
    {
        abort_unless(
            $this->actorMayMutateShare($actor, $query, $share),
            Response::HTTP_FORBIDDEN,
            __('Seul le propriétaire peut modifier ce partage.'),
        );
    }

    /**
     * @param  Collection<int, QueryUserShare>  $userShares
     * @param  Collection<int, QueryGroupShare>  $groupShares
     * @return Collection<int, User>
     */
    private function shareUsers(Collection $userShares, ?Collection $groupShares = null): Collection
    {
        $ids = $userShares
            ->flatMap(fn (QueryUserShare $share): array => [$share->user_id, $share->shared_by_user_id])
            ->concat(($groupShares ?? collect())
                ->map(fn (QueryGroupShare $share): ?int => $share->shared_by_user_id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->select(['id', 'name', 'email'])
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (User $user): int => $user->id);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function sharePayload(
        QueryUserShare $share,
        Collection $users,
        User $actor,
        Query $query,
    ): array {
        /** @var User $recipient */
        $recipient = $users->get($share->user_id);
        /** @var User|null $sharedBy */
        $sharedBy = $share->shared_by_user_id === null
            ? null
            : $users->get($share->shared_by_user_id);
        $canMutate = $share->isActive()
            && $this->actorMayMutateShare($actor, $query, $share);
        $canCancel = $share->status === QueryUserShare::STATUS_PENDING
            && $this->actorMayMutateShare($actor, $query, $share);

        return [
            'id' => $share->id,
            'recipient_type' => 'user',
            'permission' => $this->enumValue($share->permission),
            'status' => $this->shareStatus($share),
            'expires_at' => $this->isoDate($share->expires_at),
            'respond_by' => $this->isoDate($share->respond_by),
            'accepted_at' => $this->isoDate($share->accepted_at),
            'declined_at' => $this->isoDate($share->declined_at),
            'cancelled_at' => $this->isoDate($share->cancelled_at),
            'revoked_at' => $this->isoDate($share->revoked_at),
            'is_active' => $share->isActive(),
            'can_update' => $canMutate,
            'can_revoke' => $canMutate,
            'can_cancel' => $canCancel,
            'user' => $this->candidatePayload($recipient),
            'shared_by' => $sharedBy === null ? null : [
                'id' => $sharedBy->id,
                'name' => $sharedBy->name,
            ],
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function groupSharePayload(QueryGroupShare $share, Collection $users): array
    {
        /** @var User|null $sharedBy */
        $sharedBy = $share->shared_by_user_id === null
            ? null
            : $users->get($share->shared_by_user_id);
        $group = $share->relationLoaded('group') ? $share->group : null;

        return [
            'id' => $share->id,
            'recipient_type' => 'group',
            'permission' => $share->permission->value,
            'status' => $this->groupShareStatus($share),
            'expires_at' => $this->isoDate($share->expires_at),
            'respond_by' => null,
            'accepted_at' => $this->isoDate($share->accepted_at),
            'declined_at' => null,
            'cancelled_at' => null,
            'revoked_at' => $this->isoDate($share->revoked_at),
            'is_active' => $share->isActive(),
            'can_update' => $share->isActive(),
            'can_revoke' => $share->isActive(),
            'can_cancel' => false,
            'group' => [
                'recipient_type' => 'group',
                'id' => $share->group_id,
                'name' => $group->name ?? $share->group_name,
                'description' => $group->description ?? null,
                'member_count' => $group === null
                    ? 0
                    : (int) $group->getAttribute('members_count'),
            ],
            'shared_by' => $sharedBy === null ? null : [
                'id' => $sharedBy->id,
                'name' => $sharedBy->name,
            ],
        ];
    }

    /** @return array{id: int, recipient_type: string, name: string, email: string} */
    private function candidatePayload(User $user): array
    {
        return [
            'recipient_type' => 'user',
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    /** @return array{id: int, recipient_type: string, name: string, description: string|null, member_count: int} */
    private function groupCandidatePayload(Group $group): array
    {
        return [
            'recipient_type' => 'group',
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'member_count' => (int) $group->getAttribute('members_count'),
        ];
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $paginator
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function enumValue(BackedEnum|string $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    private function shareStatus(QueryUserShare $share): string
    {
        if ($share->isActive()) {
            return 'active';
        }

        if ($share->status === QueryUserShare::STATUS_PENDING && ! $share->isPending()) {
            return 'expired';
        }

        if ($share->status === QueryUserShare::STATUS_ACCEPTED
            && $share->expires_at !== null
            && $share->expires_at->lte(now())) {
            return 'expired';
        }

        return $share->status;
    }

    private function groupShareStatus(QueryGroupShare $share): string
    {
        if ($share->isActive()) {
            return 'active';
        }

        if (
            $share->status === QueryGroupShare::STATUS_ACCEPTED
            && $share->expires_at !== null
            && $share->expires_at->lte(now())
        ) {
            return 'expired';
        }

        return $share->status;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        return CarbonImmutable::parse((string) $value)->toISOString();
    }
}
