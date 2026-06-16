<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQueryRequest;
use App\Models\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

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
}
