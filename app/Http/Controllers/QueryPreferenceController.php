<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryUserPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class QueryPreferenceController extends Controller
{
    /**
     * Create, update or clear the current user's preference for a query.
     */
    public function update(Request $request, Query $query): JsonResponse
    {
        Gate::authorize('view', $query);

        $validated = $request->validate([
            'is_favorite' => ['required_without:is_pinned', 'boolean'],
            'is_pinned' => ['required_without:is_favorite', 'boolean'],
        ]);
        $userId = (int) $request->user()->id;

        /** @var array{is_favorite: bool, is_pinned: bool, pinned_at: string|null} $state */
        $state = DB::transaction(function () use ($userId, $query, $validated): array {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $preference = QueryUserPreference::query()
                ->where('user_id', $userId)
                ->where('query_id', $query->id)
                ->lockForUpdate()
                ->first();

            $isFavorite = array_key_exists('is_favorite', $validated)
                ? (bool) $validated['is_favorite']
                : ($preference === null ? false : $preference->is_favorite);
            $isPinned = array_key_exists('is_pinned', $validated)
                ? (bool) $validated['is_pinned']
                : ($preference === null ? false : $preference->is_pinned);

            if (! $isFavorite && ! $isPinned) {
                $preference?->delete();

                return [
                    'is_favorite' => false,
                    'is_pinned' => false,
                    'pinned_at' => null,
                ];
            }

            $pinnedAt = $isPinned
                ? ($preference === null ? now() : ($preference->pinned_at ?? now()))
                : null;

            $preference = QueryUserPreference::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'query_id' => $query->id,
                ],
                [
                    'is_favorite' => $isFavorite,
                    'is_pinned' => $isPinned,
                    'pinned_at' => $pinnedAt,
                ],
            );

            return [
                'is_favorite' => $preference->is_favorite,
                'is_pinned' => $preference->is_pinned,
                'pinned_at' => $preference->pinned_at?->toISOString(),
            ];
        });

        return response()->json([
            'query_id' => $query->id,
            ...$state,
        ]);
    }
}
