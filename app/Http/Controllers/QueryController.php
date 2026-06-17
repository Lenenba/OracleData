<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunQueryRequest;
use App\Http\Requests\StoreQueryRequest;
use App\Models\Query;
use App\Services\FusionManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
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
    public function index(Request $request): Response
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
                'visibility' => $query->visibility,
                'owner' => $query->user->name,
                'can' => ['update' => $query->user_id === $userId],
            ]);

        return Inertia::render('queries/index', ['queries' => $queries]);
    }

    /**
     * Show the form to create a new query.
     */
    public function create(): Response
    {
        return Inertia::render('queries/create');
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
     * Show a query's detail page with the tenant selector used to run it.
     */
    public function show(Request $request, Query $query): Response
    {
        Gate::authorize('view', $query);

        return Inertia::render('queries/show', [
            'query' => [
                'id' => $query->id,
                'name' => $query->name,
                'description' => $query->description,
                'resource_path' => $query->resource_path,
                'parameters' => (object) ($query->parameters ?? []),
                'visibility' => $query->visibility,
                'can' => ['update' => $query->user_id === $request->user()->id],
            ],
            'tenants' => app(FusionManager::class)->available(),
            'defaultTenant' => (string) config('fusion.default'),
        ]);
    }

    /**
     * Execute the query against the selected tenant and return the rows.
     */
    public function run(RunQueryRequest $request, Query $query, FusionManager $fusion): JsonResponse
    {
        Gate::authorize('view', $query);

        $tenant = $request->validated()['tenant'];

        try {
            $payload = $fusion->tenant($tenant)->get($query->resource_path, $query->parameters ?? []);
        } catch (RuntimeException $e) {
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
