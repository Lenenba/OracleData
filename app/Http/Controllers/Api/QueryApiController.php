<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\PersonalApiToken;
use App\Models\Query;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\RuntimeQueryParameterBinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lot 12B — API REST publique interne pour les requêtes sauvegardées.
 *
 * Toutes les routes de ce contrôleur sont protégées par le middleware
 * AuthenticateApiToken. L'accès respecte les mêmes règles de visibilité
 * que l'interface web (propriétaire + accès partagé).
 */
class QueryApiController extends Controller
{
    /**
     * List accessible queries (scope: read:queries).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $queries = Query::query()
            ->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhere('access_level', 'organization')
            )
            ->whereNull('deleted_at')
            ->select(['id', 'name', 'description', 'resource_path', 'mode', 'access_level', 'tenant_key', 'created_at', 'updated_at'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $queries->map(fn (Query $q): array => [
                'id' => $q->id,
                'name' => $q->name,
                'description' => $q->description,
                'resource_path' => $q->resource_path,
                'mode' => $q->mode,
                'access_level' => $q->access_level->value,
                'tenant_key' => $q->tenant_key,
                'created_at' => $q->created_at?->toIso8601String(),
                'updated_at' => $q->updated_at?->toIso8601String(),
            ]),
            'meta' => ['count' => $queries->count()],
        ]);
    }

    /**
     * Get a single query by ID (scope: read:queries).
     */
    public function show(Request $request, Query $query): JsonResponse
    {
        $this->authorizeReadAccess($request, $query);

        return response()->json([
            'id' => $query->id,
            'name' => $query->name,
            'description' => $query->description,
            'resource_path' => $query->resource_path,
            'mode' => $query->mode,
            'access_level' => $query->access_level->value,
            'tenant_key' => $query->tenant_key,
            'parameters' => $query->parameters,
            'execution_count' => $query->execution_count,
            'last_successful_at' => $query->last_successful_execution_at?->toIso8601String(),
            'created_at' => $query->created_at?->toIso8601String(),
            'updated_at' => $query->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Execute a query and return the result (scope: run:queries).
     * Limited to 500 rows; no Oracle data is stored in the response beyond what
     * the authenticated user already has access to via the web interface.
     */
    public function run(
        Request $request,
        Query $query,
        FusionManager $fusion,
        OracleQueryTool $tool,
        RuntimeQueryParameterBinder $binder,
    ): JsonResponse {
        $this->authorizeReadAccess($request, $query);

        /** @var PersonalApiToken $apiToken */
        $apiToken = $request->attributes->get('api_token');

        if (! $apiToken->hasScope(PersonalApiToken::SCOPE_RUN_QUERIES)) {
            return response()->json(['message' => 'Scope run:queries required.'], Response::HTTP_FORBIDDEN);
        }

        $fusionForUser = $fusion->forUser($request->user());
        $tenantKey = $request->query('tenant') ?? $query->tenant_key ?? $fusionForUser->defaultKey();

        if (! is_string($tenantKey) || ! $fusionForUser->has($tenantKey)) {
            return response()->json(['message' => 'No active Oracle connection available.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $params = $binder->bind($query, (array) $request->query());
        $limit = min((int) ($params['limit'] ?? 25), 500);
        $params['limit'] = $limit;

        try {
            $result = $tool->forUser($request->user())->run($tenantKey, $params);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Oracle execution failed.', 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'query_id' => $query->id,
            'tenant' => $tenantKey,
            'rows' => $result['items'],
            'count' => count($result['items']),
            'has_more' => $result['hasMore'],
        ]);
    }

    private function authorizeReadAccess(Request $request, Query $query): void
    {
        $user = $request->user();
        $canRead = Query::query()
            ->accessibleTo($user)
            ->whereKey($query->getKey())
            ->exists();

        abort_unless($canRead, Response::HTTP_NOT_FOUND);
    }
}
