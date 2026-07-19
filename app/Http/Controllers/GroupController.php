<?php

namespace App\Http\Controllers;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\QueryGroupShare;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class GroupController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Group::class);
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{group?: int|null, search?: string|null, per_page?: int, member_page?: int, candidate_page?: int} $validated */
        $validated = $request->validate([
            'group' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'member_page' => ['nullable', 'integer', 'min:1'],
            'candidate_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $groups = Group::query()
            ->forUser($actor)
            ->with([
                'owner:id,name',
                'members' => fn ($members) => $members->whereKey($actor->id),
            ])
            ->withCount('members')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $selectedId = (int) ($validated['group'] ?? ($groups->first()->id ?? 0));
        $selectedGroup = $selectedId === 0
            ? null
            : Group::query()
                ->forUser($actor)
                ->with([
                    'owner:id,name',
                    'members' => fn ($members) => $members->whereKey($actor->id),
                ])
                ->withCount('members')
                ->findOrFail($selectedId);

        $members = $selectedGroup === null
            ? $this->emptyPaginator($perPage, 'member_page')
            : $selectedGroup->members()
                ->select(['users.id', 'users.name', 'users.email'])
                ->orderByRaw("CASE group_user.role WHEN 'owner' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END")
                ->orderBy('users.name')
                ->paginate($perPage, ['*'], 'member_page');

        $canManageMembers = $selectedGroup !== null
            && $actor->can('manageMembers', $selectedGroup);
        $candidates = $selectedGroup === null || ! $canManageMembers
            ? $this->emptyPaginator($perPage, 'candidate_page')
            : User::query()
                ->select(['id', 'name', 'email'])
                ->whereNotIn('id', DB::table('group_user')
                    ->select('user_id')
                    ->where('group_id', $selectedGroup->id))
                ->when($search !== '', fn (Builder $users) => $users
                    ->where(fn (Builder $matching) => $matching
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($perPage, ['*'], 'candidate_page');

        return Inertia::render('groups/index', [
            'groups' => $groups
                ->map(fn (Group $group): array => $this->groupPayload($group, $actor))
                ->values()
                ->all(),
            'selectedGroup' => $selectedGroup === null
                ? null
                : $this->groupPayload($selectedGroup, $actor),
            'members' => [
                'data' => $members->getCollection()
                    ->map(fn (User $member): array => $this->memberPayload($member, $actor, $selectedGroup))
                    ->values()
                    ->all(),
                'meta' => $this->paginationMeta($members),
            ],
            'candidates' => [
                'data' => $candidates->getCollection()
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ])
                    ->values()
                    ->all(),
                'meta' => $this->paginationMeta($candidates),
            ],
            'search' => $search,
            'roles' => $selectedGroup?->owner_id === $actor->id
                ? [GroupRole::MANAGER->value, GroupRole::MEMBER->value]
                : ($canManageMembers ? [GroupRole::MEMBER->value] : []),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit): JsonResponse|RedirectResponse
    {
        Gate::authorize('create', Group::class);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        /** @var array{name: string, description?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        $group = DB::transaction(function () use ($actor, $audit, $validated): Group {
            $group = Group::query()->create([
                'owner_id' => $actor->id,
                'name' => trim($validated['name']),
                'description' => $this->nullableTrim($validated['description'] ?? null),
            ]);
            $group->members()->attach($actor->id, ['role' => GroupRole::OWNER->value]);
            $audit->record($actor, 'group.created', $group, [
                'owner_user_id' => $actor->id,
            ]);

            return $group;
        });

        if ($request->expectsJson()) {
            $group->loadCount('members');

            return response()->json([
                'group' => $this->groupPayload($group, $actor),
            ], Response::HTTP_CREATED);
        }

        return to_route('groups.index', ['group' => $group->id])
            ->with('status', 'group-created');
    }

    public function update(
        Request $request,
        Group $group,
        AuditRecorder $audit,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $group);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        /** @var array{name: string, description?: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        $group = DB::transaction(function () use ($actor, $audit, $group, $validated): Group {
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('update', $lockedGroup);
            $lockedGroup->forceFill([
                'name' => trim($validated['name']),
                'description' => $this->nullableTrim($validated['description'] ?? null),
            ])->save();
            $audit->record($actor, 'group.updated', $lockedGroup, [
                'changed_fields' => ['name', 'description'],
            ]);

            return $lockedGroup->refresh();
        });

        if ($request->expectsJson()) {
            $group->loadCount('members');

            return response()->json(['group' => $this->groupPayload($group, $actor)]);
        }

        return back()->with('status', 'group-updated');
    }

    public function destroy(
        Request $request,
        Group $group,
        AuditRecorder $audit,
    ): Response {
        Gate::authorize('delete', $group);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $group): void {
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('delete', $lockedGroup);
            $shares = QueryGroupShare::query()
                ->where('group_id', $lockedGroup->id)
                ->active()
                ->with('sharedQuery')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($shares as $share) {
                $share->revoke();
                $audit->record($actor, 'query.group_share_revoked', $share->sharedQuery, [
                    'group_share_id' => $share->id,
                    'group_id' => $lockedGroup->id,
                    'permission' => $share->permission->value,
                    'reason' => 'group_archived',
                ]);
            }

            $audit->record($actor, 'group.archived', $lockedGroup, [
                'revoked_group_share_count' => $shares->count(),
            ]);
            $lockedGroup->delete();
        });

        return $request->expectsJson()
            ? response()->noContent()
            : to_route('groups.index')->with('status', 'group-archived');
    }

    /** @return array<string, mixed> */
    private function groupPayload(Group $group, User $actor): array
    {
        $role = $group->roleFor($actor);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'member_count' => (int) $group->getAttribute('members_count'),
            'current_user_role' => $role?->value,
            'can_update' => $actor->can('update', $group),
            'can_delete' => $actor->can('delete', $group),
            'can_manage_members' => $actor->can('manageMembers', $group),
        ];
    }

    /** @return array<string, mixed> */
    private function memberPayload(User $member, User $actor, ?Group $group): array
    {
        $role = GroupRole::from((string) $member->getRelation('pivot')->getAttribute('role'));
        $actorRole = $group?->roleFor($actor);
        $isOwner = $role === GroupRole::OWNER;
        $isSelf = $member->id === $actor->id;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $role->value,
            'can_update_role' => $actorRole === GroupRole::OWNER && ! $isOwner,
            'can_remove' => ! $isOwner && ! $isSelf && (
                $actorRole === GroupRole::OWNER
                || ($actorRole === GroupRole::MANAGER && $role === GroupRole::MEMBER)
            ),
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

    /** @return LengthAwarePaginator<int, never> */
    private function emptyPaginator(int $perPage, string $pageName): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage, 1, [
            'pageName' => $pageName,
            'path' => request()->url(),
        ]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
