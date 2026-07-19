<?php

namespace App\Http\Controllers;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GroupMemberController extends Controller
{
    public function store(
        Request $request,
        Group $group,
        AuditRecorder $audit,
    ): JsonResponse {
        Gate::authorize('manageMembers', $group);
        /** @var array{user_id: int, role: string} $validated */
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'role' => ['required', Rule::in([GroupRole::MANAGER->value, GroupRole::MEMBER->value])],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $group, $validated): void {
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('manageMembers', $lockedGroup);
            $role = GroupRole::from($validated['role']);

            if ($role === GroupRole::MANAGER && $lockedGroup->owner_id !== $actor->id) {
                throw ValidationException::withMessages([
                    'role' => __('Seul le propriétaire peut nommer un gestionnaire de groupe.'),
                ]);
            }

            $member = User::query()->lockForUpdate()->findOrFail($validated['user_id']);
            abort_if(
                DB::table('group_user')
                    ->where('group_id', $lockedGroup->id)
                    ->where('user_id', $member->id)
                    ->exists(),
                Response::HTTP_CONFLICT,
                __('Cette personne appartient déjà au groupe.'),
            );
            $lockedGroup->members()->attach($member->id, ['role' => $role->value]);
            $audit->record($actor, 'group.member_added', $lockedGroup, [
                'member_user_id' => $member->id,
                'role' => $role->value,
            ]);
        });

        return response()->json([], Response::HTTP_CREATED);
    }

    public function update(
        Request $request,
        Group $group,
        User $user,
        AuditRecorder $audit,
    ): JsonResponse {
        Gate::authorize('manageMembers', $group);
        /** @var array{role: string} $validated */
        $validated = $request->validate([
            'role' => ['required', Rule::in([GroupRole::MANAGER->value, GroupRole::MEMBER->value])],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $group, $user, $validated): void {
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('manageMembers', $lockedGroup);
            abort_unless($lockedGroup->owner_id === $actor->id, Response::HTTP_FORBIDDEN);
            abort_if($user->id === $lockedGroup->owner_id, Response::HTTP_FORBIDDEN);
            $membership = DB::table('group_user')
                ->where('group_id', $lockedGroup->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            abort_if($membership === null, Response::HTTP_NOT_FOUND);
            $role = GroupRole::from($validated['role']);
            $before = (string) $membership->role;
            $lockedGroup->members()->updateExistingPivot($user->id, ['role' => $role->value]);
            $audit->record($actor, 'group.member_role_updated', $lockedGroup, [
                'member_user_id' => $user->id,
                'before' => $before,
                'after' => $role->value,
            ]);
        });

        return response()->json();
    }

    public function destroy(
        Request $request,
        Group $group,
        User $user,
        AuditRecorder $audit,
    ): Response {
        Gate::authorize('manageMembers', $group);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $group, $user): void {
            $lockedGroup = Group::query()->lockForUpdate()->findOrFail($group->id);
            Gate::forUser($actor)->authorize('manageMembers', $lockedGroup);
            abort_if($user->id === $lockedGroup->owner_id || $user->id === $actor->id, Response::HTTP_FORBIDDEN);
            $membership = DB::table('group_user')
                ->where('group_id', $lockedGroup->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            abort_if($membership === null, Response::HTTP_NOT_FOUND);
            $role = GroupRole::from((string) $membership->role);

            if ($lockedGroup->owner_id !== $actor->id && $role !== GroupRole::MEMBER) {
                abort(Response::HTTP_FORBIDDEN);
            }

            $lockedGroup->members()->detach($user->id);
            $audit->record($actor, 'group.member_removed', $lockedGroup, [
                'member_user_id' => $user->id,
                'role' => $role->value,
            ]);
        });

        return response()->noContent();
    }
}
