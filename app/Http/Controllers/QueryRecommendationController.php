<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lot 12E — Moteur de recommandations de requêtes.
 *
 * Renvoie les requêtes les plus susceptibles d'intéresser l'utilisateur
 * courant, basées sur :
 *  1. les requêtes qu'il exécute le plus souvent (poids 3) ;
 *  2. les requêtes récentes partagées avec lui qu'il n'a pas encore exécutées ;
 *  3. les requêtes populaires de l'organisation (poids 1).
 *
 * Aucun appel Oracle — uniquement des agrégats DB.
 */
class QueryRecommendationController extends Controller
{
    private const int LIMIT = 8;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $userId = $user->id;

        // ── 1. Requêtes personnelles les plus exécutées récemment ──────────
        $frequent = Query::query()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('execution_count', '>', 0)
            ->orderByDesc('last_successful_execution_at')
            ->orderByDesc('execution_count')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'description', 'resource_path', 'mode', 'access_level', 'execution_count', 'last_successful_execution_at']);

        $recommendedIds = $frequent->pluck('id')->all();

        // ── 2. Requêtes org récentes non encore exécutées par cet utilisateur ─
        $executedIds = QueryExecution::query()
            ->where('user_id', $userId)
            ->pluck('query_id')
            ->unique()
            ->all();

        $fresh = Query::query()
            ->where('access_level', 'organization')
            ->whereNull('deleted_at')
            ->whereNotIn('id', array_merge($recommendedIds, $executedIds))
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'description', 'resource_path', 'mode', 'access_level', 'execution_count', 'last_successful_execution_at']);

        $merged = $frequent->concat($fresh)->unique('id')->take(self::LIMIT);

        return response()->json([
            'recommendations' => $merged->map(fn (Query $q): array => [
                'id'             => $q->id,
                'name'           => $q->name,
                'description'    => $q->description,
                'resource_path'  => $q->resource_path,
                'mode'           => $q->mode,
                'access_level'   => $q->access_level->value,
                'execution_count' => $q->execution_count,
                'last_run_at'    => $q->last_successful_execution_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
