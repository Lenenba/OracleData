<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunQueryRequest;
use App\Http\Requests\StoreQueryRequest;
use App\Models\Query;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\OracleResourceCatalog;
use App\Services\QueryAgent;
use App\Services\QueryResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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

        $queries = Query::query()
            ->where(fn (Builder $query) => $query
                ->where('user_id', $userId)
                ->orWhere('visibility', 'shared'))
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn (Query $query): array => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'mode' => $query->mode,
                'tenant' => [
                    'key' => $query->tenant_key,
                    'label' => $fusion->label($query->tenant_key),
                ],
                'visibility' => $query->visibility,
                'owner' => $query->user->name,
                'can' => ['update' => $query->user_id === $userId],
            ]);

        return Inertia::render('queries/index', ['queries' => $queries]);
    }

    /**
     * Show the form to create a new query.
     */
    public function create(OracleResourceCatalog $catalog, FusionManager $fusion): Response
    {
        return Inertia::render('queries/create', [
            'resourceSuggestions' => $catalog->suggestions(),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->defaultKey(),
        ]);
    }

    /**
     * Persist a new query owned by the current user.
     */
    public function store(StoreQueryRequest $request): RedirectResponse
    {
        $data = $request->validated();

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

        $request->user()->queries()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête enregistrée.')]);

        return to_route('queries.index');
    }

    /**
     * Show the wizard pre-filled for editing an existing query.
     */
    public function edit(Request $request, Query $query, OracleResourceCatalog $catalog, FusionManager $fusion): Response
    {
        Gate::authorize('update', $query);

        return Inertia::render('queries/edit', [
            'query' => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'tenant_key' => $query->tenant_key,
                'mode' => $query->mode,
                'parameters' => (object) ($query->parameters ?? []),
                'visibility' => $query->visibility,
            ],
            'resourceSuggestions' => $catalog->suggestions(),
            'tenants' => $fusion->available(),
            'defaultTenant' => $query->tenant_key ?: $fusion->defaultKey(),
        ]);
    }

    /**
     * Update an existing query owned by the current user.
     */
    public function update(StoreQueryRequest $request, Query $query): RedirectResponse
    {
        Gate::authorize('update', $query);

        $data = $request->validated();

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
     * Execute a direct Oracle query from the wizard (resource already chosen — no LLM needed).
     * Accepts: resource_key, tenant, fields[], expand[], joins[], child_fields{}, limit.
     */
    public function directPreview(Request $request, FusionManager $fusion, OracleQueryTool $tool): JsonResponse
    {
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

        return Inertia::render('queries/show', [
            'query' => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'tenant_key' => $query->tenant_key,
                'mode' => $query->mode,
                'parameters' => (object) ($query->parameters ?? []),
                'visibility' => $query->visibility,
                'can' => ['update' => $query->user_id === $request->user()->id],
            ],
            'tenants' => $fusion->available(),
            'defaultTenant' => $query->tenant_key ?: $fusion->defaultKey(),
        ]);
    }

    /**
     * Execute the query against the selected tenant and return the rows.
     */
    public function run(RunQueryRequest $request, Query $query, FusionManager $fusion, QueryAgent $agent, OracleQueryTool $tool): JsonResponse
    {
        Gate::authorize('view', $query);

        $tenant = (string) ($request->validated()['tenant'] ?? $query->tenant_key ?? $fusion->defaultKey());

        if ($query->mode === 'agent') {
            return response()->json($this->runAgent($tenant, (string) ($query->description ?? ''), $agent));
        }

        $parameters = $query->parameters ?? [];

        // Requête issue du wizard (resource_key présent) : rejouée via l'outil
        // garde-fou, ce qui ré-applique validation, projection et jointures.
        if (! empty($parameters['resource_key'])) {
            return response()->json($this->runSingle($tenant, $this->toolQueryFromParameters($parameters), $tool));
        }

        try {
            $payload = $fusion->tenant($tenant)->get((string) $query->resource_path, $parameters);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json($this->basePayload($tenant, 'single', $e->getMessage()));
        }

        /** @var array<int, mixed> $items */
        $items = (array) OracleQueryTool::withoutLinks($payload['items'] ?? []);

        return response()->json(array_replace($this->basePayload($tenant, 'single'), [
            'items' => $items,
            'count' => $payload['count'] ?? count($items),
            'hasMore' => $payload['hasMore'] ?? false,
        ]));
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
