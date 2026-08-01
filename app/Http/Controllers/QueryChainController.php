<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQueryChainRequest;
use App\Models\Query;
use App\Models\QueryChain;
use App\Services\FusionManager;
use App\Services\QueryChainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lot chaining — CRUD des chaînes de requêtes + exécution du chaînage.
 *
 * Chaque chaîne appartient à l'utilisateur propriétaire de la requête
 * principale. L'accès à la requête secondaire est contrôlé à l'exécution.
 */
class QueryChainController extends Controller
{
    /**
     * List all chains for a primary query (owner only).
     */
    public function index(Request $request, Query $query): JsonResponse
    {
        Gate::authorize('update', $query);

        $chains = QueryChain::query()
            ->where('primary_query_id', $query->id)
            ->where('user_id', $request->user()->id)
            ->orderBy('position')
            ->with(['secondaryQuery:id,name,resource_path'])
            ->get()
            ->map(fn (QueryChain $c): array => [
                'id' => $c->id,
                'secondary_query_id' => $c->secondary_query_id,
                'secondary_name' => $c->secondaryQuery->name,
                'extraction_field' => $c->extraction_field,
                'injection_param' => $c->injection_param,
                'injection_operator' => $c->injection_operator,
                'label' => $c->label,
                'position' => $c->position,
            ]);

        return response()->json($chains);
    }

    /**
     * Create a new chain on a primary query.
     */
    public function store(StoreQueryChainRequest $request, Query $query): JsonResponse
    {
        Gate::authorize('update', $query);

        $count = QueryChain::query()
            ->where('primary_query_id', $query->id)
            ->count();

        abort_if(
            $count >= QueryChain::MAX_PER_QUERY,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Une requête ne peut pas avoir plus de :max chaînes.', ['max' => QueryChain::MAX_PER_QUERY]),
        );

        $v = $request->validated();
        $secondaryId = (int) $v['secondary_query_id'];

        abort_if(
            $secondaryId === $query->id,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('La requête secondaire ne peut pas être la même que la requête principale.'),
        );

        $chain = QueryChain::create([
            'primary_query_id' => $query->id,
            'secondary_query_id' => $secondaryId,
            'user_id' => $request->user()->id,
            'extraction_field' => $v['extraction_field'],
            'injection_param' => $v['injection_param'],
            'injection_operator' => $v['injection_operator'],
            'label' => $v['label'] ?? null,
            'position' => $v['position'] ?? ($count),
        ]);

        return response()->json([
            'id' => $chain->id,
            'secondary_query_id' => $chain->secondary_query_id,
            'extraction_field' => $chain->extraction_field,
            'injection_param' => $chain->injection_param,
            'injection_operator' => $chain->injection_operator,
            'label' => $chain->label,
            'position' => $chain->position,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update a chain's configuration (label, operator, position).
     */
    public function update(StoreQueryChainRequest $request, Query $query, QueryChain $chain): JsonResponse
    {
        Gate::authorize('update', $query);
        abort_unless($chain->primary_query_id === $query->id, Response::HTTP_NOT_FOUND);
        abort_unless($chain->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $v = $request->validated();
        $chain->update([
            'extraction_field' => $v['extraction_field'],
            'injection_param' => $v['injection_param'],
            'injection_operator' => $v['injection_operator'],
            'label' => $v['label'] ?? null,
            'position' => $v['position'] ?? $chain->position,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete a chain.
     */
    public function destroy(Request $request, Query $query, QueryChain $chain): JsonResponse
    {
        Gate::authorize('update', $query);
        abort_unless($chain->primary_query_id === $query->id, Response::HTTP_NOT_FOUND);
        abort_unless($chain->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $chain->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Execute the chained secondary query using the IDs extracted from the
     * provided primary items.
     *
     * Body: { primary_items: [...], tenant: string }
     */
    public function run(
        Request $request,
        Query $query,
        QueryChain $chain,
        QueryChainService $service,
        FusionManager $fusion,
    ): JsonResponse {
        Gate::authorize('execute', $query);
        abort_unless($chain->primary_query_id === $query->id, Response::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'primary_items' => ['required', 'array', 'max:500'],
            'primary_items.*' => ['array'],
            'tenant' => ['required', 'string'],
        ]);

        $tenantKey = (string) $validated['tenant'];
        $primaryItems = [];

        foreach ($validated['primary_items'] as $item) {
            if (is_array($item)) {
                $primaryItems[] = $item;
            }
        }

        abort_unless(
            $fusion->forUser($request->user())->has($tenantKey),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Environnement Oracle non disponible.'),
        );

        try {
            $result = $service->runSecondary(
                $chain,
                $primaryItems,
                $tenantKey,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json($result);
    }
}
