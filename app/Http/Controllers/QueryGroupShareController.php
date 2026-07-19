<?php

namespace App\Http\Controllers;

use App\Enums\QuerySharePermission;
use App\Models\Group;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\QueryShareLifecycleService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class QueryGroupShareController extends Controller
{
    public function store(
        Request $request,
        Query $query,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse {
        Gate::authorize('manageSharing', $query);
        /** @var array{group_id: int, permission: string, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->whereNull('deleted_at')],
            'permission' => ['required', Rule::in($this->allowedPermissions())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        $share = DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $validated): QueryGroupShare {
            $lockedQuery = $lifecycle->lockManageableQuery($actor, $query);
            $group = Group::query()
                ->forUser($actor)
                ->lockForUpdate()
                ->findOrFail($validated['group_id']);
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
                'first_group_share',
            );

            $alreadyActive = QueryGroupShare::query()
                ->where('query_id', $lockedQuery->id)
                ->where('group_id', $group->id)
                ->active()
                ->lockForUpdate()
                ->exists();
            abort_if(
                $alreadyActive,
                Response::HTTP_CONFLICT,
                __('Ce groupe dispose déjà d’un partage actif.'),
            );

            $share = QueryGroupShare::query()->create([
                'query_id' => $lockedQuery->id,
                'group_id' => $group->id,
                'group_name' => $group->name,
                'shared_by_user_id' => $actor->id,
                'permission' => $permission,
                'status' => QueryGroupShare::STATUS_ACCEPTED,
                'expires_at' => $validated['expires_at'] ?? null,
                'accepted_at' => now(),
                'revoked_at' => null,
            ]);
            $audit->record($actor, 'query.group_shared', $lockedQuery, [
                'group_share_id' => $share->id,
                'group_id' => $group->id,
                'permission' => $share->permission->value,
                'expires_at' => $this->isoDate($share->expires_at),
            ]);

            return $share->refresh();
        });

        $share->load([
            'group' => fn ($groups) => $groups->withCount('members'),
            'sharedByUser:id,name',
        ]);

        return response()->json(['share' => $this->payload($share)], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        Query $query,
        QueryGroupShare $queryGroupShare,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): JsonResponse {
        Gate::authorize('manageSharing', $query);
        $this->assertBelongsToQuery($query, $queryGroupShare);

        if (! $request->hasAny(['permission', 'expires_at'])) {
            throw ValidationException::withMessages([
                'permission' => __('Une permission ou une expiration doit être fournie.'),
            ]);
        }

        /** @var array{permission?: string, expires_at?: string|null} $validated */
        $validated = $request->validate([
            'permission' => ['sometimes', 'required', Rule::in($this->allowedPermissions())],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        $share = DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $queryGroupShare, $validated): QueryGroupShare {
            $lockedQuery = $lifecycle->lockManageableQuery($actor, $query);
            $share = QueryGroupShare::query()->lockForUpdate()->findOrFail($queryGroupShare->id);
            $this->assertBelongsToQuery($lockedQuery, $share);
            abort_unless(
                $share->isActive() && $share->group()->exists(),
                Response::HTTP_CONFLICT,
                __('Un partage inactif doit être recréé.'),
            );
            $before = [
                'permission' => $share->permission->value,
                'expires_at' => $this->isoDate($share->expires_at),
            ];
            $permission = array_key_exists('permission', $validated)
                ? QuerySharePermission::from($validated['permission'])
                : $share->permission;
            $expiresAt = array_key_exists('expires_at', $validated)
                ? $validated['expires_at']
                : $share->expires_at;
            $lifecycle->assertDelegationWithinActorGrant(
                $actor,
                $lockedQuery,
                $permission,
                $expiresAt,
            );
            $changes = [];

            if (array_key_exists('permission', $validated)) {
                $changes['permission'] = $validated['permission'];
            }

            if (array_key_exists('expires_at', $validated)) {
                $changes['expires_at'] = $validated['expires_at'];
            }

            $share->forceFill($changes)->save();
            $share->refresh();
            $audit->record($actor, 'query.group_share_updated', $lockedQuery, [
                'group_share_id' => $share->id,
                'group_id' => $share->group_id,
                'before' => $before,
                'after' => [
                    'permission' => $share->permission->value,
                    'expires_at' => $this->isoDate($share->expires_at),
                ],
            ]);

            return $share;
        });

        $share->load([
            'group' => fn ($groups) => $groups->withCount('members'),
            'sharedByUser:id,name',
        ]);

        return response()->json(['share' => $this->payload($share)]);
    }

    public function destroy(
        Request $request,
        Query $query,
        QueryGroupShare $queryGroupShare,
        AuditRecorder $audit,
        QueryShareLifecycleService $lifecycle,
    ): Response {
        Gate::authorize('manageSharing', $query);
        $this->assertBelongsToQuery($query, $queryGroupShare);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $lifecycle, $query, $queryGroupShare): void {
            $lockedQuery = $lifecycle->lockManageableQuery($actor, $query);
            $share = QueryGroupShare::query()->lockForUpdate()->findOrFail($queryGroupShare->id);
            $this->assertBelongsToQuery($lockedQuery, $share);

            if (! $share->isActive()) {
                return;
            }

            $share->revoke();
            $audit->record($actor, 'query.group_share_revoked', $lockedQuery, [
                'group_share_id' => $share->id,
                'group_id' => $share->group_id,
                'permission' => $share->permission->value,
                'reason' => 'direct_revocation',
            ]);
        });

        return response()->noContent();
    }

    private function assertBelongsToQuery(Query $query, QueryGroupShare $share): void
    {
        abort_unless($share->query_id === $query->id, Response::HTTP_NOT_FOUND);
    }

    /** @return list<string> */
    private function allowedPermissions(): array
    {
        return [
            QuerySharePermission::VIEW->value,
            QuerySharePermission::EXECUTE->value,
            QuerySharePermission::CLONE->value,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(QueryGroupShare $share): array
    {
        $group = $share->relationLoaded('group') ? $share->group : null;
        $sharedBy = $share->relationLoaded('sharedByUser') ? $share->sharedByUser : null;

        return [
            'id' => $share->id,
            'recipient_type' => 'group',
            'permission' => $share->permission->value,
            'status' => $share->isActive() ? 'active' : $share->status,
            'expires_at' => $this->isoDate($share->expires_at),
            'accepted_at' => $this->isoDate($share->accepted_at),
            'revoked_at' => $this->isoDate($share->revoked_at),
            'is_active' => $share->isActive(),
            'can_update' => $share->isActive(),
            'can_revoke' => $share->isActive(),
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

    private function isoDate(?CarbonInterface $value): ?string
    {
        return $value?->toISOString();
    }
}
