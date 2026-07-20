<?php

namespace App\Http\Controllers;

use App\Enums\OracleExecutionPolicy;
use App\Http\Requests\RunQueryRequest;
use App\Http\Requests\StoreQueryRequest;
use App\Models\Category;
use App\Models\Query;
use App\Models\SavedQueryView;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\OracleFieldDiscovery;
use App\Services\OracleQueryTool;
use App\Services\OracleResourceCatalog;
use App\Services\QueryAgent;
use App\Services\QueryChangeRequestService;
use App\Services\QueryExecutionRecorder;
use App\Services\QueryResolver;
use App\Services\QueryShareLifecycleService;
use App\Services\SemanticCatalogReader;
use App\Services\SemanticLineageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class QueryController extends Controller
{
    /**
     * Paramètres REST Oracle conservés lors de l'enregistrement d'une requête single.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PARAMETER_KEYS = ['limit', 'q', 'fields', 'expand', 'joins', 'child_fields', 'orderBy', 'offset'];

    /**
     * List the user's own queries plus every query explicitly accessible to them.
     */
    public function index(Request $request, FusionManager $fusion): Response
    {
        $user = $request->user();
        $userId = $user->id;
        $groupIds = $user->groupIdsForQueryAccess();
        $locale = app()->getLocale();
        $fusion = $fusion->forUser($request->user());

        $skipDefaultView = $request->string('view')->toString() === 'none';

        if (! $skipDefaultView && ! $request->hasAny(['scope', 'search', 'category', 'tag', 'sort', 'favorite', 'pinned'])) {
            $defaultView = $request->user()
                ->savedQueryViews()
                ->where('is_default', true)
                ->first();

            if ($defaultView !== null) {
                $request->merge($defaultView->filters);
            }
        }

        $scope = $request->string('scope')->toString();
        $scope = in_array($scope, ['all', 'mine', 'shared'], true) ? $scope : 'all';
        $search = trim($request->string('search')->toString());
        $category = trim($request->string('category')->toString());
        $tag = trim($request->string('tag')->toString());
        $sort = $request->string('sort')->toString();
        $sort = in_array($sort, [
            'updated_desc',
            'updated_asc',
            'name_asc',
            'name_desc',
            'executions_desc',
            'last_executed_desc',
        ], true) ? $sort : 'updated_desc';
        $favorite = $request->boolean('favorite');
        $pinned = $request->boolean('pinned');

        if (mb_strlen($search) > 100) {
            $search = mb_substr($search, 0, 100);
        }

        $accessible = fn (): Builder => Query::query()->accessibleTo($user);

        $queryBuilder = Query::query()
            ->select([
                'id',
                'user_id',
                'name',
                'description',
                'resource_path',
                'tenant_key',
                'mode',
                'access_level',
                'category_id',
                'execution_count',
                'successful_execution_count',
                'last_executed_at',
                'updated_at',
            ])
            ->when($scope === 'all', fn (Builder $query) => $query->accessibleTo($user))
            ->when($scope === 'mine', fn (Builder $query) => $query
                ->where('user_id', $userId))
            ->when($scope === 'shared', fn (Builder $query) => $query->sharedWith($user))
            ->when($search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('tags', fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhereHas('translations', fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")))))
            ->when($category !== '', fn (Builder $query) => $query
                ->whereHas('category', fn (Builder $query) => $query
                    ->where('slug', $category)))
            ->when($tag !== '', fn (Builder $query) => $query
                ->whereHas('tags', fn (Builder $query) => $query
                    ->where('slug', $tag)))
            ->when($favorite, fn (Builder $query) => $query
                ->whereHas('preferences', fn (Builder $query) => $query
                    ->where('user_id', $userId)
                    ->where('is_favorite', true)))
            ->when($pinned, fn (Builder $query) => $query
                ->whereHas('preferences', fn (Builder $query) => $query
                    ->where('user_id', $userId)
                    ->where('is_pinned', true)))
            ->withExists([
                'preferences as is_favorite' => fn (Builder $query) => $query
                    ->where('user_id', $userId)
                    ->where('is_favorite', true),
                'preferences as is_pinned' => fn (Builder $query) => $query
                    ->where('user_id', $userId)
                    ->where('is_pinned', true),
            ])
            ->with([
                'user:id,name',
                'category.translations',
                'tags.translations',
                'userShares' => fn ($query) => $query->where('user_id', $userId),
                'groupShares' => fn ($query) => $query
                    ->active()
                    ->whereIn('group_id', $groupIds),
            ])
            ->orderByDesc('is_pinned');

        match ($sort) {
            'updated_asc' => $queryBuilder->orderBy('updated_at'),
            'name_asc' => $queryBuilder->orderBy('name'),
            'name_desc' => $queryBuilder->orderByDesc('name'),
            'executions_desc' => $queryBuilder->orderByDesc('execution_count'),
            'last_executed_desc' => $queryBuilder
                ->orderByRaw('CASE WHEN last_executed_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('last_executed_at'),
            default => $queryBuilder->orderByDesc('updated_at'),
        };

        $queries = $queryBuilder
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Query $query): array => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'mode' => $query->mode,
                'tenant' => [
                    'key' => $query->user_id === $userId ? $query->tenant_key : null,
                    'label' => $query->user_id === $userId
                        ? $fusion->label($query->tenant_key)
                        : null,
                ],
                'access_level' => $query->access_level,
                'owner' => $query->user->name,
                'category' => $query->category === null ? null : [
                    'slug' => $query->category->slug,
                    'name' => $query->category->nameFor($locale),
                    'color' => $query->category->color,
                ],
                'tags' => $query->tags
                    ->map(fn (Tag $tag): array => ['name' => $tag->nameFor($locale), 'slug' => $tag->slug])
                    ->values(),
                'preference' => [
                    'is_favorite' => (bool) $query->getAttribute('is_favorite'),
                    'is_pinned' => (bool) $query->getAttribute('is_pinned'),
                ],
                'statistics' => [
                    'execution_count' => (int) $query->execution_count,
                    'success_rate' => $query->execution_count > 0
                        ? (int) round(($query->successful_execution_count / $query->execution_count) * 100)
                        : null,
                    'last_executed_at' => $query->last_executed_at?->toISOString(),
                ],
                'can' => [
                    'update' => $query->user_id === $userId,
                    'execute' => $user->can('execute', $query),
                    'clone' => $user->can('clone', $query),
                    'manage_sharing' => $user->can('manageSharing', $query),
                    'request_change' => $user->can('requestChange', $query),
                ],
            ]);

        $usage = $accessible()
            ->selectRaw('COALESCE(SUM(execution_count), 0) as total_executions')
            ->selectRaw('COALESCE(SUM(successful_execution_count), 0) as successful_executions')
            ->selectRaw('MAX(last_executed_at) as last_executed_at')
            ->first();
        $totalExecutions = (int) ($usage?->getAttribute('total_executions') ?? 0);
        $successfulExecutions = (int) ($usage?->getAttribute('successful_executions') ?? 0);

        return Inertia::render('queries/index', [
            'queries' => $queries,
            'scope' => $scope,
            'search' => $search,
            'category' => $category,
            'tag' => $tag,
            'sort' => $sort,
            'favorite' => $favorite,
            'pinned' => $pinned,
            'categories' => $this->categoryOptions($locale),
            'tags' => $this->tagOptions($locale, $user),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->defaultKey(),
            'summary' => [
                'all' => $accessible()->count(),
                'mine' => Query::query()->where('user_id', $userId)->count(),
                'shared' => Query::query()->sharedWith($user)->count(),
                'favorites' => $accessible()
                    ->whereHas('preferences', fn (Builder $query) => $query
                        ->where('user_id', $userId)
                        ->where('is_favorite', true))
                    ->count(),
                'pinned' => $accessible()
                    ->whereHas('preferences', fn (Builder $query) => $query
                        ->where('user_id', $userId)
                        ->where('is_pinned', true))
                    ->count(),
            ],
            'usage' => [
                'total_executions' => $totalExecutions,
                'success_rate' => $totalExecutions > 0
                    ? round(($successfulExecutions / $totalExecutions) * 100, 1)
                    : null,
                'last_executed_at' => $usage?->getAttribute('last_executed_at'),
            ],
            'savedViews' => $request->user()
                ->savedQueryViews()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'filters', 'is_default'])
                ->map(fn (SavedQueryView $view): array => [
                    'id' => $view->id,
                    'name' => $view->name,
                    'filters' => $view->filters,
                    'is_default' => $view->is_default,
                ]),
        ]);
    }

    /**
     * Shortcut page for the shared query library.
     */
    public function shared(Request $request, FusionManager $fusion): Response
    {
        $request->merge(['scope' => 'shared']);

        return $this->index($request, $fusion);
    }

    /**
     * Show the form to create a new query.
     */
    public function create(
        Request $request,
        OracleResourceCatalog $catalog,
        SemanticCatalogReader $semanticCatalog,
        FusionManager $fusion,
    ): Response {
        $fusion = $fusion->forUser($request->user());

        return Inertia::render('queries/create', [
            'resourceSuggestions' => $this->semanticSuggestions($semanticCatalog, $catalog),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->defaultKey(),
            'categories' => $this->categoryOptions(app()->getLocale()),
            'tags' => $this->tagOptions(app()->getLocale(), $request->user()),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function semanticSuggestions(
        SemanticCatalogReader $semanticCatalog,
        OracleResourceCatalog $fallback,
    ): array {
        if ($semanticCatalog->currentVersion() === null) {
            return $fallback->suggestions();
        }

        return $semanticCatalog->suggestions(app()->getLocale());
    }

    /**
     * Categories available to classify a query, labelled in the given locale.
     *
     * @return list<array{id: int, slug: string, name: string, color: string|null}>
     */
    private function categoryOptions(string $locale): array
    {
        return array_values(Category::query()
            ->with('translations')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->nameFor($locale),
                'color' => $category->color,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /**
     * Existing tags offered as suggestions while still allowing free input.
     *
     * @return list<array{id: int, slug: string, name: string, label: string}>
     */
    private function tagOptions(string $locale, User $user): array
    {
        return array_values(Tag::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('translations')
                ->orWhereHas('queries', function (Builder $query) use ($user): void {
                    /** @var Builder<Query> $query */
                    $query->accessibleTo($user);
                }))
            ->with('translations')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'slug' => $tag->slug,
                'name' => $tag->name,
                'label' => $tag->nameFor($locale),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());
    }

    /**
     * Persist a new query owned by the current user.
     */
    public function store(
        StoreQueryRequest $request,
        FusionManager $fusion,
        SemanticLineageService $lineage,
    ): RedirectResponse {
        $data = $request->validated();
        $tags = Arr::pull($data, 'tags');
        $fusion = $fusion->forUser($request->user());
        $data['oracle_tenant_id'] = $fusion->tenantId($data['tenant_key']);

        if (($data['mode'] ?? 'single') === 'agent') {
            $data['resource_path'] = null;
            $data['parameters'] = null;
        } else {
            $rawParams = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
            $params = (new Collection(Arr::only($rawParams, self::ALLOWED_PARAMETER_KEYS)))
                ->reject(fn ($value): bool => $value === null || $value === '')
                ->all();

            // Persist resource_key so the wizard can be pre-filled on edit.
            if (! empty($rawParams['resource_key'])) {
                $params['resource_key'] = (string) $rawParams['resource_key'];
            }

            $data['parameters'] = $params;
        }

        $query = $request->user()->queries()->create($data);
        $lineage->syncQuery($query, (array) ($query->parameters ?? []));

        if (is_array($tags)) {
            $this->syncTags($query, $this->normaliseTags($tags));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête enregistrée.')]);

        return to_route('queries.index');
    }

    /**
     * Attach free-form tag names, creating missing tags by normalised slug.
     *
     * @param  list<string>  $tags
     */
    private function syncTags(Query $query, array $tags): void
    {
        $query->tags()->sync(
            (new Collection($tags))
                ->filter(fn (string $name): bool => trim($name) !== '')
                ->map(fn (string $name): int => Tag::findOrCreateByName($name)->id)
                ->unique()
                ->all(),
        );
    }

    /**
     * @param  array<mixed>  $tags
     * @return list<string>
     */
    private function normaliseTags(array $tags): array
    {
        return array_values(array_filter($tags, is_string(...)));
    }

    /**
     * Show the wizard pre-filled for editing an existing query.
     */
    public function edit(
        Request $request,
        Query $query,
        OracleResourceCatalog $catalog,
        SemanticCatalogReader $semanticCatalog,
        FusionManager $fusion,
    ): Response {
        Gate::authorize('update', $query);
        $query->loadMissing('queryTemplate.translations');
        $fusion = $fusion->forUser($request->user());

        return Inertia::render('queries/edit', [
            'query' => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'tenant_key' => $query->user_id === $request->user()->id
                    ? $query->tenant_key
                    : null,
                'mode' => $query->mode,
                'parameters' => (object) ($query->parameters ?? []),
                'access_level' => $query->access_level,
                'category_id' => $query->category_id,
                'tags' => $query->tags->pluck('name')->values(),
                'source_template' => $query->queryTemplate === null ? null : [
                    'slug' => $query->queryTemplate->slug,
                    'name' => $query->queryTemplate->nameFor(app()->getLocale()),
                ],
            ],
            'resourceSuggestions' => $this->semanticSuggestions($semanticCatalog, $catalog),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->has($query->tenant_key)
                ? $query->tenant_key
                : $fusion->defaultKey(),
            'categories' => $this->categoryOptions(app()->getLocale()),
            'tags' => $this->tagOptions(app()->getLocale(), $request->user()),
        ]);
    }

    /**
     * Update an existing query owned by the current user.
     */
    public function update(
        StoreQueryRequest $request,
        Query $query,
        FusionManager $fusion,
        SemanticLineageService $lineage,
    ): RedirectResponse {
        Gate::authorize('update', $query);

        $data = $request->validated();
        $tags = Arr::pull($data, 'tags');
        // Existing access grants are governed atomically by the dedicated
        // sharing workflow (audit, revocation and expiry), never by metadata
        // edits in the query builder.
        Arr::forget($data, 'access_level');
        $fusion = $fusion->forUser($request->user());
        $data['oracle_tenant_id'] = $fusion->tenantId($data['tenant_key']);

        if (($data['mode'] ?? 'single') === 'agent') {
            $data['resource_path'] = null;
            $data['parameters'] = null;
        } else {
            $rawParams = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
            $params = (new Collection(Arr::only($rawParams, self::ALLOWED_PARAMETER_KEYS)))
                ->reject(fn ($value): bool => $value === null || $value === '')
                ->all();

            if (! empty($rawParams['resource_key'])) {
                $params['resource_key'] = (string) $rawParams['resource_key'];
            }

            $data['parameters'] = $params;
        }

        $query->update($data);
        $lineage->syncQuery($query, (array) ($query->parameters ?? []));

        if (is_array($tags)) {
            $this->syncTags($query, $this->normaliseTags($tags));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête mise à jour.')]);

        return to_route('queries.show', $query);
    }

    /**
     * Delete a query owned by the current user.
     */
    public function destroy(
        Request $request,
        Query $query,
        QueryShareLifecycleService $lifecycle,
        QueryChangeRequestService $changeRequests,
        AuditRecorder $audit,
    ): RedirectResponse {
        Gate::authorize('delete', $query);
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $audit, $changeRequests, $lifecycle, $query): void {
            $lockedQuery = Query::query()->lockForUpdate()->findOrFail($query->id);
            Gate::forUser($actor)->authorize('delete', $lockedQuery);
            $revoked = $lifecycle->revokeActiveGrants(
                $actor,
                $lockedQuery,
                $audit,
                'query_archived',
            );
            $cancelledChangeRequests = $changeRequests->cancelForArchivedQuery(
                $actor,
                $lockedQuery,
                $audit,
            );
            $audit->record($actor, 'query.archived', $lockedQuery, [
                'access_level' => $lockedQuery->access_level->value,
                'revoked_share_count' => $revoked['total'],
                'revoked_user_share_count' => $revoked['users'],
                'revoked_group_share_count' => $revoked['groups'],
                'cancelled_invitation_count' => $revoked['invitations'],
                'cancelled_change_request_count' => $cancelledChangeRequests,
            ]);
            $lockedQuery->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête supprimée.')]);

        return to_route('queries.index');
    }

    /**
     * Copy a visible query into the current user's private library.
     */
    public function duplicate(Request $request, Query $query, FusionManager $fusion): RedirectResponse
    {
        Gate::authorize('clone', $query);
        $fusion = $fusion->forUser($request->user());
        $tenantKey = $fusion->defaultKey();

        $copy = $request->user()->queries()->create([
            'name' => Str::limit(__('Copie de :name', ['name' => $query->name]), 255, ''),
            'description' => $query->description,
            'resource_path' => $query->resource_path,
            'tenant_key' => $tenantKey,
            'oracle_tenant_id' => $fusion->tenantId($tenantKey),
            'mode' => $query->mode,
            'execution_policy' => $query->execution_policy ?? OracleExecutionPolicy::BEST_EFFORT,
            'parameters' => $query->parameters,
            'access_level' => 'private',
            'category_id' => $query->category_id,
            'query_template_id' => $query->query_template_id,
        ]);
        $copy->forceFill([
            'query_template_version_id' => $query->getAttribute('query_template_version_id'),
        ])->save();

        $copy->tags()->sync($query->tags()->pluck('tags.id'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copie créée. Vous pouvez maintenant la modifier.')]);

        return to_route('queries.edit', $copy);
    }

    /**
     * Execute a direct Oracle query from the wizard (resource already chosen — no LLM needed).
     * Accepts: resource_key, tenant, fields[], expand[], joins[], child_fields{}, limit.
     */
    /**
     * Discover the fields actually exposed by a catalog resource (or one of
     * its expand children) on one of the reader's tenants.
     */
    public function resourceFields(Request $request, FusionManager $fusion, OracleFieldDiscovery $discovery): JsonResponse
    {
        $fusion = $fusion->forUser($request->user());

        $validated = $request->validate([
            'tenant' => ['required', 'string', Rule::in($fusion->keys())],
            'resource_key' => ['required', 'string', 'max:100'],
            'child' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = $discovery->fields(
                $request->user(),
                $validated['tenant'],
                $validated['resource_key'],
                $validated['child'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function directPreview(Request $request, FusionManager $fusion, OracleQueryTool $tool): JsonResponse
    {
        $fusion = $fusion->forUser($request->user());

        $validated = $request->validate([
            'resource_key' => ['required', 'string'],
            'tenant' => ['nullable', 'string', Rule::in($fusion->keys())],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
            'expand' => ['nullable', 'array'],
            'expand.*' => ['string'],
            'joins' => ['nullable', 'array'],
            'joins.*' => ['string'],
            'child_fields' => ['nullable', 'array'],
            'child_fields.*' => ['array'],
            'child_fields.*.*' => ['string', 'max:100'],
            'filter_q' => ['nullable', 'string', 'max:500'],
            'order_by' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $tenant = (string) ($validated['tenant'] ?? $fusion->defaultKey());

        $query = [
            'resource' => $validated['resource_key'],
            'fields' => $validated['fields'] ?? [],
            'expand' => $validated['expand'] ?? [],
            'joins' => $validated['joins'] ?? [],
            'child_fields' => $validated['child_fields'] ?? [],
            'limit' => $validated['limit'] ?? 25,
        ];

        if (! empty($validated['filter_q'])) {
            $query['q'] = $validated['filter_q'];
        }

        if (! empty($validated['order_by'])) {
            $query['orderBy'] = $validated['order_by'];
        }

        return response()->json($this->runSingle($tenant, $query, $tool));
    }

    /**
     * Resolve a natural-language intent and preview rows without saving it.
     */
    public function preview(Request $request, FusionManager $fusion, QueryResolver $resolver, OracleQueryTool $tool, QueryAgent $agent): JsonResponse
    {
        $fusion = $fusion->forUser($request->user());

        $validated = $request->validate([
            'intent' => ['required', 'string', 'max:2000'],
            'tenant' => ['nullable', 'string', Rule::in($fusion->keys())],
            'parameters' => ['nullable', 'array'],
            'parameters.limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $tenant = (string) ($validated['tenant'] ?? $fusion->defaultKey());
        $intent = (string) $validated['intent'];

        try {
            $resolved = $resolver->resolve($intent, data_get($validated, 'parameters.limit'));
        } catch (RuntimeException $e) {
            return response()->json($this->basePayload($tenant, 'single', $e->getMessage()));
        }

        return match ($resolved['mode']) {
            'clarify' => response()->json(array_replace(
                $this->basePayload($tenant, 'clarify'),
                ['clarification' => $resolved['question']],
            )),
            'agent' => response()->json($this->runAgent($tenant, $intent, $agent)),
            default => response()->json($this->runSingle($tenant, $resolved['query'], $tool)),
        };
    }

    /**
     * Show a query's detail page with the tenant selector used to run it.
     */
    public function show(Request $request, Query $query, FusionManager $fusion): Response
    {
        Gate::authorize('view', $query);
        $groupIds = $request->user()->groupIdsForQueryAccess();
        $query->loadMissing([
            'category.translations',
            'tags.translations',
            'userShares' => fn ($share) => $share->where('user_id', $request->user()->id),
            'groupShares' => fn ($share) => $share
                ->active()
                ->whereIn('group_id', $groupIds),
        ]);
        $fusion = $fusion->forUser($request->user());
        $locale = app()->getLocale();
        $ownerPreferredTenant = $query->user_id === $request->user()->id
            && $fusion->has($query->tenant_key)
                ? $query->tenant_key
                : null;

        return Inertia::render('queries/show', [
            'query' => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'tenant_key' => $query->user_id === $request->user()->id
                    ? $query->tenant_key
                    : null,
                'mode' => $query->mode,
                'parameters' => (object) ($query->parameters ?? []),
                'access_level' => $query->access_level,
                'category' => $query->category === null ? null : [
                    'slug' => $query->category->slug,
                    'name' => $query->category->nameFor($locale),
                    'color' => $query->category->color,
                ],
                'tags' => $query->tags
                    ->map(fn (Tag $tag): array => [
                        'slug' => $tag->slug,
                        'name' => $tag->nameFor($locale),
                    ])
                    ->values(),
                'can' => [
                    'update' => $query->user_id === $request->user()->id,
                    'execute' => $request->user()->can('execute', $query),
                    'clone' => $request->user()->can('clone', $query),
                    'manage_sharing' => $request->user()->can('manageSharing', $query),
                    'request_change' => $request->user()->can('requestChange', $query),
                ],
            ],
            'tenants' => $fusion->available(),
            'defaultTenant' => $ownerPreferredTenant ?: $fusion->defaultKey(),
        ]);
    }

    /**
     * Execute the query against the selected tenant and return the rows.
     */
    public function run(RunQueryRequest $request, Query $query, FusionManager $fusion, OracleQueryTool $tool, AuditRecorder $audit, QueryExecutionRecorder $executions): JsonResponse
    {
        Gate::authorize('execute', $query);

        // Agent analyses now run asynchronously via AgentAnalysisRunController so
        // they no longer block the request cycle. See étape 9 (lot 9A).
        abort_if(
            $query->mode === 'agent',
            422,
            __('Les analyses agent s’exécutent de façon asynchrone.'),
        );

        $fusion = $fusion->forUser($request->user());

        $ownerPreferredTenant = $query->user_id === $request->user()->id
            && $fusion->has($query->tenant_key)
                ? $query->tenant_key
                : null;
        $tenant = (string) ($request->validated()['tenant'] ?? $ownerPreferredTenant ?? $fusion->defaultKey());

        $audit->record($request->user(), 'query.executed', $query, [
            'tenant_key' => $tenant,
            'mode' => $query->mode,
        ]);

        $startedAt = now();
        $startedAtNs = hrtime(true);
        $payload = $this->executeQuery($query, $tenant, $fusion, $tool);
        $durationMs = (int) round((hrtime(true) - $startedAtNs) / 1_000_000);

        $executions->recordQueryRun($request->user(), $query, $tenant, $payload, $startedAt, $durationMs);

        return response()->json($payload);
    }

    /**
     * Build the run payload for every execution mode through a single exit
     * point, so duration and outcome can be recorded uniformly.
     *
     * @return array<string, mixed>
     */
    private function executeQuery(Query $query, string $tenant, FusionManager $fusion, OracleQueryTool $tool): array
    {
        $parameters = $query->parameters ?? [];

        // Requête issue du wizard (resource_key présent) : rejouée via l'outil
        // garde-fou, ce qui ré-applique validation, projection et jointures.
        if (! empty($parameters['resource_key'])) {
            return $this->runSingle(
                $tenant,
                $this->toolQueryFromParameters($parameters),
                $tool,
                $query->execution_policy ?? OracleExecutionPolicy::BEST_EFFORT,
            );
        }

        try {
            $payload = $fusion->tenant($tenant)->get((string) $query->resource_path, $parameters);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->basePayload($tenant, 'single', $e->getMessage());
        }

        /** @var array<int, mixed> $items */
        $items = (array) OracleQueryTool::withoutLinks($payload['items'] ?? []);

        return array_replace($this->basePayload($tenant, 'single'), [
            'items' => $items,
            'count' => $payload['count'] ?? count($items),
            'hasMore' => $payload['hasMore'] ?? false,
        ]);
    }

    /**
     * Reconstruit la requête structurée de l'outil Oracle à partir des
     * paramètres persistés d'une requête créée par le wizard.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function toolQueryFromParameters(array $parameters): array
    {
        $toolQuery = [
            'resource' => (string) $parameters['resource_key'],
            'fields' => $parameters['fields'] ?? [],
            'expand' => $parameters['expand'] ?? [],
            'joins' => $parameters['joins'] ?? [],
            'child_fields' => $parameters['child_fields'] ?? [],
            'limit' => $parameters['limit'] ?? 25,
        ];

        foreach (['q', 'orderBy', 'offset'] as $key) {
            if (isset($parameters[$key]) && $parameters[$key] !== '') {
                $toolQuery[$key] = $parameters[$key];
            }
        }

        return $toolQuery;
    }

    /**
     * Execute a resolved single-resource query through the guarded Oracle tool.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function runSingle(
        string $tenant,
        array $query,
        OracleQueryTool $tool,
        OracleExecutionPolicy $policy = OracleExecutionPolicy::BEST_EFFORT,
    ): array {
        try {
            $result = $tool->run($tenant, $query, $policy);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->basePayload($tenant, 'single', $e->getMessage());
        }

        return array_replace($this->basePayload($tenant, 'single'), [
            'resource' => $result['resource'],
            // Spécification canonique rejouable (resource_key, joins…), pas les
            // paramètres REST bruts : c'est elle que le front persiste.
            'parameters' => (object) $result['query'],
            'items' => $result['items'],
            'count' => $result['count'],
            'hasMore' => $result['hasMore'],
            'oracleCalls' => array_map(
                fn (array $call): array => array_replace($call, ['params' => (object) $call['params']]),
                $result['calls'],
            ),
        ]);
    }

    /**
     * Run the multi-resource analysis agent and normalise its result.
     *
     * @return array<string, mixed>
     */
    private function runAgent(string $tenant, string $intent, QueryAgent $agent): array
    {
        try {
            $result = $agent->run($tenant, $intent);
        } catch (RuntimeException $e) {
            return $this->basePayload($tenant, 'agent', $e->getMessage());
        }

        return array_replace($this->basePayload($tenant, 'agent'), [
            'columns' => $result['columns'],
            'items' => $result['rows'],
            'count' => count($result['rows']),
            'analysis' => $result['analysis'],
            'oracleCalls' => $result['oracleCalls'],
        ]);
    }

    /**
     * Common JSON shape shared by preview and run responses.
     *
     * @return array<string, mixed>
     */
    private function basePayload(string $tenant, string $mode, ?string $error = null): array
    {
        return [
            'mode' => $mode,
            'tenant' => $tenant,
            'resource' => null,
            'parameters' => null,
            'columns' => null,
            'analysis' => null,
            'items' => [],
            'count' => 0,
            'hasMore' => false,
            'oracleCalls' => [],
            'clarification' => null,
            'error' => $error,
        ];
    }
}
