<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunQueryRequest;
use App\Http\Requests\StoreQueryRequest;
use App\Models\Category;
use App\Models\Query;
use App\Models\Tag;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\OracleResourceCatalog;
use App\Services\QueryAgent;
use App\Services\QueryExecutionRecorder;
use App\Services\QueryResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class QueryController extends Controller
{
    /**
     * Paramètres REST Oracle conservés lors de l'enregistrement d'une requête single.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PARAMETER_KEYS = ['limit', 'q', 'fields', 'expand', 'joins', 'child_fields', 'orderBy', 'offset'];

    /**
     * List the user's own queries plus every shared query.
     */
    public function index(Request $request, FusionManager $fusion): Response
    {
        $userId = $request->user()->id;
        $locale = app()->getLocale();
        $fusion = $fusion->forUser($request->user());
        $scope = $request->string('scope')->toString();
        $scope = in_array($scope, ['all', 'mine', 'shared'], true) ? $scope : 'all';
        $search = trim($request->string('search')->toString());
        $category = trim($request->string('category')->toString());
        $tag = trim($request->string('tag')->toString());

        if (mb_strlen($search) > 100) {
            $search = mb_substr($search, 0, 100);
        }

        $queries = Query::query()
            ->select([
                'id',
                'user_id',
                'name',
                'description',
                'resource_path',
                'tenant_key',
                'mode',
                'visibility',
                'category_id',
                'updated_at',
            ])
            ->when($scope === 'all', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('user_id', $userId)
                    ->orWhere('visibility', 'shared')))
            ->when($scope === 'mine', fn (Builder $query) => $query
                ->where('user_id', $userId))
            ->when($scope === 'shared', fn (Builder $query) => $query
                ->where('visibility', 'shared'))
            ->when($search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('tags', fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%"))))
            ->when($category !== '', fn (Builder $query) => $query
                ->whereHas('category', fn (Builder $query) => $query
                    ->where('slug', $category)))
            ->when($tag !== '', fn (Builder $query) => $query
                ->whereHas('tags', fn (Builder $query) => $query
                    ->where('slug', $tag)))
            ->with(['user:id,name', 'category.translations', 'tags:tags.id,name,slug'])
            ->orderByDesc('updated_at')
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
                'visibility' => $query->visibility,
                'owner' => $query->user->name,
                'category' => $query->category === null ? null : [
                    'slug' => $query->category->slug,
                    'name' => $query->category->nameFor($locale),
                    'color' => $query->category->color,
                ],
                'tags' => $query->tags
                    ->map(fn (Tag $tag): array => ['name' => $tag->name, 'slug' => $tag->slug])
                    ->values(),
                'can' => [
                    'update' => $query->user_id === $userId,
                    'clone' => true,
                ],
            ]);

        return Inertia::render('queries/index', [
            'queries' => $queries,
            'scope' => $scope,
            'search' => $search,
            'category' => $category,
            'tag' => $tag,
            'categories' => $this->categoryOptions($locale),
            'summary' => [
                'all' => Query::query()
                    ->where(fn (Builder $query) => $query
                        ->where('user_id', $userId)
                        ->orWhere('visibility', 'shared'))
                    ->count(),
                'mine' => Query::query()->where('user_id', $userId)->count(),
                'shared' => Query::query()->where('visibility', 'shared')->count(),
            ],
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
    public function create(Request $request, OracleResourceCatalog $catalog, FusionManager $fusion): Response
    {
        $fusion = $fusion->forUser($request->user());

        return Inertia::render('queries/create', [
            'resourceSuggestions' => $catalog->suggestions(),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->defaultKey(),
            'categories' => $this->categoryOptions(app()->getLocale()),
        ]);
    }

    /**
     * Categories available to classify a query, labelled in the given locale.
     *
     * @return list<array{id: int, slug: string, name: string, color: string|null}>
     */
    private function categoryOptions(string $locale): array
    {
        return Category::query()
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
            ->all();
    }

    /**
     * Persist a new query owned by the current user.
     */
    public function store(StoreQueryRequest $request, FusionManager $fusion): RedirectResponse
    {
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

        if (is_array($tags)) {
            $this->syncTags($query, $tags);
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
     * Show the wizard pre-filled for editing an existing query.
     */
    public function edit(Request $request, Query $query, OracleResourceCatalog $catalog, FusionManager $fusion): Response
    {
        Gate::authorize('update', $query);
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
                'visibility' => $query->visibility,
                'category_id' => $query->category_id,
                'tags' => $query->tags->pluck('name')->values(),
            ],
            'resourceSuggestions' => $catalog->suggestions(),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->has($query->tenant_key)
                ? $query->tenant_key
                : $fusion->defaultKey(),
            'categories' => $this->categoryOptions(app()->getLocale()),
        ]);
    }

    /**
     * Update an existing query owned by the current user.
     */
    public function update(StoreQueryRequest $request, Query $query, FusionManager $fusion): RedirectResponse
    {
        Gate::authorize('update', $query);

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

            if (! empty($rawParams['resource_key'])) {
                $params['resource_key'] = (string) $rawParams['resource_key'];
            }

            $data['parameters'] = $params;
        }

        $query->update($data);

        if (is_array($tags)) {
            $this->syncTags($query, $tags);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête mise à jour.')]);

        return to_route('queries.show', $query);
    }

    /**
     * Delete a query owned by the current user.
     */
    public function destroy(Request $request, Query $query): RedirectResponse
    {
        Gate::authorize('update', $query);

        $query->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête supprimée.')]);

        return to_route('queries.index');
    }

    /**
     * Toggle the visibility of a query (private ↔ shared).
     */
    public function updateVisibility(Request $request, Query $query): SymfonyResponse
    {
        Gate::authorize('update', $query);

        $validated = $request->validate([
            'visibility' => ['required', 'in:private,shared'],
        ]);

        $query->update(['visibility' => $validated['visibility']]);

        if ($request->expectsJson()) {
            return response()->json(['visibility' => $query->visibility]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visibilité mise à jour.')]);

        return back();
    }

    /**
     * Copy a visible query into the current user's private library.
     */
    public function duplicate(Request $request, Query $query, FusionManager $fusion): RedirectResponse
    {
        Gate::authorize('view', $query);
        $fusion = $fusion->forUser($request->user());
        $tenantKey = $fusion->defaultKey();

        $copy = $request->user()->queries()->create([
            'name' => Str::limit(__('Copie de :name', ['name' => $query->name]), 255, ''),
            'description' => $query->description,
            'resource_path' => $query->resource_path,
            'tenant_key' => $tenantKey,
            'oracle_tenant_id' => $fusion->tenantId($tenantKey),
            'mode' => $query->mode,
            'parameters' => $query->parameters,
            'visibility' => 'private',
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copie créée. Vous pouvez maintenant la modifier.')]);

        return to_route('queries.edit', $copy);
    }

    /**
     * Execute a direct Oracle query from the wizard (resource already chosen — no LLM needed).
     * Accepts: resource_key, tenant, fields[], expand[], joins[], child_fields{}, limit.
     */
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
        $fusion = $fusion->forUser($request->user());
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
                'visibility' => $query->visibility,
                'can' => [
                    'update' => $query->user_id === $request->user()->id,
                    'clone' => true,
                ],
            ],
            'tenants' => $fusion->available(),
            'defaultTenant' => $ownerPreferredTenant ?: $fusion->defaultKey(),
        ]);
    }

    /**
     * Execute the query against the selected tenant and return the rows.
     */
    public function run(RunQueryRequest $request, Query $query, FusionManager $fusion, QueryAgent $agent, OracleQueryTool $tool, AuditRecorder $audit, QueryExecutionRecorder $executions): JsonResponse
    {
        Gate::authorize('view', $query);
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
        $payload = $this->executeQuery($query, $tenant, $fusion, $agent, $tool);
        $durationMs = (int) round((hrtime(true) - $startedAtNs) / 1_000_000);

        $executions->record($request->user(), $query, $tenant, $payload, $startedAt, $durationMs);

        return response()->json($payload);
    }

    /**
     * Build the run payload for every execution mode through a single exit
     * point, so duration and outcome can be recorded uniformly.
     *
     * @return array<string, mixed>
     */
    private function executeQuery(Query $query, string $tenant, FusionManager $fusion, QueryAgent $agent, OracleQueryTool $tool): array
    {
        if ($query->mode === 'agent') {
            return $this->runAgent($tenant, (string) ($query->description ?? ''), $agent);
        }

        $parameters = $query->parameters ?? [];

        // Requête issue du wizard (resource_key présent) : rejouée via l'outil
        // garde-fou, ce qui ré-applique validation, projection et jointures.
        if (! empty($parameters['resource_key'])) {
            return $this->runSingle($tenant, $this->toolQueryFromParameters($parameters), $tool);
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
    private function runSingle(string $tenant, array $query, OracleQueryTool $tool): array
    {
        try {
            $result = $tool->run($tenant, $query);
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
        } catch (InvalidArgumentException|RuntimeException $e) {
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
