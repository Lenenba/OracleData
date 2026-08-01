<?php

namespace App\Http\Controllers;

use App\Services\OracleResourceCatalog;
use App\Services\QueryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lot 11D — Copilote IA pour le builder de requêtes.
 *
 * Prend une intention en langage naturel (≥ 20 caractères) et renvoie :
 *  - `resources`  : liste des ressources du catalogue triées par pertinence
 *  - `suggestion` : résumé de la résolution LLM (mode, ressource cible ou plan)
 *
 * Pas d'appel Oracle, pas de données sensibles — le LLM ne voit que
 * l'intention et le catalogue de ressources.
 */
class QueryCopilotController extends Controller
{
    /**
     * Suggest matching resources and a resolution plan for a natural-language intent.
     */
    public function suggest(
        Request $request,
        OracleResourceCatalog $catalog,
        QueryResolver $resolver,
    ): JsonResponse {
        $validated = $request->validate([
            'intent' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $intent = (string) $validated['intent'];

        // Resolve intent via LLM (catalog-only, no Oracle call)
        try {
            $resolution = $resolver->resolve($intent);
        } catch (\Throwable) {
            $resolution = ['mode' => 'clarify', 'question' => null];
        }

        // Build resource ranking: the resolved resource comes first
        $resolvedResourceKey = null;

        if (($resolution['mode'] ?? '') === 'single' && isset($resolution['query']['resource'])) {
            $resolvedResourceKey = (string) $resolution['query']['resource'];
        }

        $allResources = $catalog->all();

        // Score each resource: exact key match = 100, keyword match = 10 each,
        // label/description fuzzy contains = 5 each — deterministic, no re-call.
        $intentLower = mb_strtolower($intent);
        $scored = [];

        foreach ($allResources as $resource) {
            $score = 0;

            if ($resource['key'] === $resolvedResourceKey) {
                $score += 100;
            }

            foreach ($resource['keywords'] as $kw) {
                if (str_contains($intentLower, mb_strtolower((string) $kw))) {
                    $score += 10;
                }
            }

            if (str_contains($intentLower, mb_strtolower($resource['label']))) {
                $score += 5;
            }

            $scored[] = ['key' => $resource['key'], 'label' => $resource['label'], 'domain' => $resource['domain'], 'score' => $score];
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return response()->json([
            'suggestion' => $resolution,
            'resources' => array_slice($scored, 0, 6),
        ]);
    }
}
