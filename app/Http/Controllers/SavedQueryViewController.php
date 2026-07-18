<?php

namespace App\Http\Controllers;

use App\Models\SavedQueryView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SavedQueryViewController extends Controller
{
    /** @var list<string> */
    private const FILTER_KEYS = [
        'scope',
        'search',
        'category',
        'tag',
        'sort',
        'favorite',
        'pinned',
    ];

    /** @var list<string> */
    private const SORTS = [
        'updated_desc',
        'updated_asc',
        'name_asc',
        'name_desc',
        'executions_desc',
        'last_executed_desc',
    ];

    /**
     * Store a named view for the authenticated user.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $this->validateView($request);
        $userId = (int) $request->user()->id;

        $view = DB::transaction(function () use ($userId, $validated): SavedQueryView {
            $this->lockOwner($userId);

            $isDefault = (bool) ($validated['is_default'] ?? false);

            if ($isDefault) {
                $this->clearDefault($userId);
            }

            return SavedQueryView::query()->create([
                'user_id' => $userId,
                'name' => $validated['name'],
                'filters' => $this->normaliseFilters($validated['filters']),
                'is_default' => $isDefault,
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json($this->payload($view), Response::HTTP_CREATED);
        }

        return back()->with('status', 'saved-query-view-created');
    }

    /**
     * Replace an owned saved view.
     */
    public function update(Request $request, SavedQueryView $savedQueryView): JsonResponse|RedirectResponse
    {
        $this->assertOwnedByRequestUser($request, $savedQueryView);

        $validated = $this->validateView($request, $savedQueryView);
        $userId = (int) $request->user()->id;

        $view = DB::transaction(function () use ($userId, $savedQueryView, $validated): SavedQueryView {
            $this->lockOwner($userId);

            $view = SavedQueryView::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($savedQueryView->id);
            $isDefault = (bool) ($validated['is_default'] ?? false);

            if ($isDefault) {
                $this->clearDefault($userId, $view->id);
            }

            $view->update([
                'name' => $validated['name'],
                'filters' => $this->normaliseFilters($validated['filters']),
                'is_default' => $isDefault,
            ]);

            return $view->refresh();
        });

        if ($request->expectsJson()) {
            return response()->json($this->payload($view));
        }

        return back()->with('status', 'saved-query-view-updated');
    }

    /**
     * Delete an owned saved view.
     */
    public function destroy(Request $request, SavedQueryView $savedQueryView): Response
    {
        $this->assertOwnedByRequestUser($request, $savedQueryView);
        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($userId, $savedQueryView): void {
            $this->lockOwner($userId);

            SavedQueryView::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->findOrFail($savedQueryView->id)
                ->delete();
        });

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back()->with('status', 'saved-query-view-deleted');
    }

    /**
     * @return array{name: string, filters: array<string, bool|string|null>, is_default?: bool}
     */
    private function validateView(Request $request, ?SavedQueryView $view = null): array
    {
        $userId = (int) $request->user()->id;

        /** @var array{name: string, filters: array<string, bool|string|null>, is_default?: bool} */
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('saved_query_views', 'name')
                    ->where(fn (QueryBuilder $query): QueryBuilder => $query->where('user_id', $userId))
                    ->ignore($view?->id),
            ],
            'filters' => ['present', 'array:'.implode(',', self::FILTER_KEYS)],
            'filters.scope' => ['nullable', Rule::in(['all', 'mine', 'shared'])],
            'filters.search' => ['nullable', 'string', 'max:100'],
            'filters.category' => ['nullable', 'string', 'max:64'],
            'filters.tag' => ['nullable', 'string', 'max:64'],
            'filters.sort' => ['nullable', Rule::in(self::SORTS)],
            'filters.favorite' => ['nullable', 'boolean'],
            'filters.pinned' => ['nullable', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertOwnedByRequestUser(Request $request, SavedQueryView $view): void
    {
        abort_unless($view->user_id === (int) $request->user()->id, Response::HTTP_NOT_FOUND);
    }

    private function lockOwner(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    private function clearDefault(int $userId, ?int $exceptId = null): void
    {
        SavedQueryView::query()
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->when($exceptId !== null, fn (EloquentBuilder $query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }

    /**
     * @param  array<string, bool|string|null>  $filters
     * @return array<string, bool|string>
     */
    private function normaliseFilters(array $filters): array
    {
        return collect(Arr::only($filters, self::FILTER_KEYS))
            ->reject(fn (bool|string|null $value): bool => $value === null || $value === '')
            ->all();
    }

    /**
     * @return array{id: int, name: string, filters: array<string, bool|string>, is_default: bool, created_at: string|null, updated_at: string|null}
     */
    private function payload(SavedQueryView $view): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'filters' => $view->filters,
            'is_default' => $view->is_default,
            'created_at' => $view->created_at?->toISOString(),
            'updated_at' => $view->updated_at?->toISOString(),
        ];
    }
}
