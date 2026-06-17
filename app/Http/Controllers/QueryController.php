<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunQueryRequest;
use App\Http\Requests\StoreQueryRequest;
use App\Models\Query;
use App\Services\FusionManager;
use App\Services\OracleResourceCatalog;
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

class QueryController extends Controller
{
    /**
     * Paramètres de requête conservés lors de l'enregistrement (tout le reste est ignoré).
     *
     * @var array<int, string>
     */
    private const ALLOWED_PARAMETER_KEYS = ['limit', 'q', 'fields', 'offset'];

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
        $data['parameters'] = (new Collection(Arr::only($data['parameters'] ?? [], self::ALLOWED_PARAMETER_KEYS)))
            ->reject(fn ($value): bool => $value === null)
            ->all();

        $request->user()->queries()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Requête enregistrée.')]);

        return to_route('queries.index');
    }

    /**
     * Resolve a natural-language intent and preview rows without saving it.
     */
    public function preview(Request $request, FusionManager $fusion, OracleResourceCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'string', 'max:1000'],
            'tenant' => ['nullable', 'string', Rule::in($fusion->keys())],
            'parameters' => ['nullable', 'array'],
            'parameters.limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $tenant = (string) ($validated['tenant'] ?? $fusion->defaultKey());
        $resource = $catalog->match($validated['intent']);

        if ($resource === null) {
            return response()->json([
                'tenant' => $tenant,
                'resource' => null,
                'items' => [],
                'count' => 0,
                'hasMore' => false,
                'error' => __('Aucune API Oracle reconnue pour cette demande.'),
            ]);
        }

        $parameters = [
            'limit' => $catalog->clampLimit(data_get($validated, 'parameters.limit')),
        ];

        try {
            $payload = $fusion->tenant($tenant)->get($resource['path'], $parameters);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json([
                'tenant' => $tenant,
                'resource' => $catalog->toSuggestion($resource),
                'items' => [],
                'count' => 0,
                'hasMore' => false,
                'error' => $e->getMessage(),
            ]);
        }

        $items = $payload['items'] ?? [];

        return response()->json([
            'tenant' => $tenant,
            'resource' => $catalog->toSuggestion($resource),
            'items' => $items,
            'count' => $payload['count'] ?? count($items),
            'hasMore' => $payload['hasMore'] ?? false,
            'error' => null,
        ]);
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
    public function run(RunQueryRequest $request, Query $query, FusionManager $fusion): JsonResponse
    {
        Gate::authorize('view', $query);

        $tenant = (string) ($request->validated()['tenant'] ?? $query->tenant_key ?? $fusion->defaultKey());

        try {
            $payload = $fusion->tenant($tenant)->get($query->resource_path, $query->parameters ?? []);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json([
                'tenant' => $tenant,
                'items' => [],
                'count' => 0,
                'hasMore' => false,
                'error' => $e->getMessage(),
            ]);
        }

        $items = $payload['items'] ?? [];

        return response()->json([
            'tenant' => $tenant,
            'items' => $items,
            'count' => $payload['count'] ?? count($items),
            'hasMore' => $payload['hasMore'] ?? false,
            'error' => null,
        ]);
    }
}
