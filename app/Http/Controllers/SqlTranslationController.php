<?php

namespace App\Http\Controllers;

use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\SqlQueryParser;
use App\Services\SqlToApiPlanBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Lot 11C — Traducteur SQL standard → plan d'appels API Oracle REST.
 *
 * Deux endpoints :
 *  - POST /queries/sql-translate  → traduit le SQL en plan, sans Oracle
 *  - POST /queries/sql-translate/run → traduit puis exécute si confiance suffisante
 *
 * Aucune instruction de mutation ne peut atteindre Oracle. Le SQL ne contient
 * jamais de secrets ni d'identifiants techniques dans les logs d'audit.
 */
class SqlTranslationController extends Controller
{
    /**
     * Translate a SQL SELECT into an API execution plan.
     *
     * Returns the plan, equivalence level and fragments. Does not call Oracle.
     */
    public function translate(
        Request $request,
        SqlQueryParser $parser,
        SqlToApiPlanBuilder $planner,
        AuditRecorder $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'sql' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $sql = (string) $validated['sql'];

        try {
            $ast = $parser->parse($sql);
            $plan = $planner->build($ast);
        } catch (RuntimeException $e) {
            $key = $e->getMessage();

            return response()->json([
                'error' => $key,
                'error_message' => __($key),
                'equivalence' => 'impossible',
                'plan' => null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $audit->record($request->user(), 'query.sql_translated', $request->user(), [
            'equivalence' => $plan['equivalence'],
            'resource_key' => $plan['resource_key'],
            'fragment_count' => count($plan['fragments']),
        ]);

        return response()->json([
            'equivalence' => $plan['equivalence'],
            'confidence' => $plan['confidence'],
            'plan' => $plan,
            'fragments' => $plan['fragments'],
        ]);
    }

    /**
     * Translate and execute a SQL SELECT via the Oracle REST API.
     *
     * Only runs when the plan equivalence is "exact" or "partial" and the
     * caller explicitly accepts the equivalence level.
     */
    public function run(
        Request $request,
        SqlQueryParser $parser,
        SqlToApiPlanBuilder $planner,
        OracleQueryTool $tool,
        FusionManager $fusion,
        AuditRecorder $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'sql' => ['required', 'string', 'min:10', 'max:10000'],
            'tenant_key' => ['nullable', 'string', 'max:100'],
            'accept_equivalence' => ['required', 'string', 'in:exact,partial'],
        ]);

        $sql = (string) $validated['sql'];
        $acceptEquivalence = (string) $validated['accept_equivalence'];

        // Translate
        try {
            $ast = $parser->parse($sql);
            $plan = $planner->build($ast);
        } catch (RuntimeException $e) {
            $key = $e->getMessage();

            return response()->json([
                'error' => $key,
                'error_message' => __($key),
                'equivalence' => 'impossible',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Enforce equivalence policy: if the plan is less precise than accepted, refuse
        $rank = ['exact' => 2, 'partial' => 1, 'impossible' => 0];
        if (($rank[$plan['equivalence']] ?? 0) < ($rank[$acceptEquivalence] ?? 2)) {
            return response()->json([
                'error' => 'sqlTranslator.errorEquivalenceTooLow',
                'error_message' => __('sqlTranslator.errorEquivalenceTooLow'),
                'equivalence' => $plan['equivalence'],
                'plan' => $plan,
                'fragments' => $plan['fragments'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Resolve Oracle tenant
        $fusionForUser = $fusion->forUser($request->user());
        $tenantKey = (string) ($validated['tenant_key'] ?? $fusionForUser->defaultKey());

        abort_unless(
            $fusionForUser->has($tenantKey),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Configurez une connexion Oracle active avant d\'exécuter la traduction.'),
        );

        // Execute via Oracle query tool
        $query = [
            'resource' => $plan['resource_key'],
            'fields' => $plan['fields'],
            'limit' => $plan['limit'],
            'offset' => $plan['offset'],
            'joins' => $plan['joins'],
        ];

        if ($plan['q'] !== '') {
            $query['q'] = $plan['q'];
        }

        if ($plan['orderBy'] !== '') {
            $query['orderBy'] = $plan['orderBy'];
        }

        $toolForUser = $tool->forUser($request->user());

        try {
            $result = $toolForUser->run($tenantKey, $query);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'sqlTranslator.errorExecutionFailed',
                'error_message' => $e->getMessage(),
                'equivalence' => $plan['equivalence'],
                'plan' => $plan,
                'fragments' => $plan['fragments'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => 'sqlTranslator.errorOracleFailed',
                'error_message' => $e->getMessage(),
                'equivalence' => $plan['equivalence'],
                'plan' => $plan,
                'fragments' => $plan['fragments'],
            ], Response::HTTP_BAD_GATEWAY);
        }

        $audit->record($request->user(), 'query.sql_translated_and_run', $request->user(), [
            'equivalence' => $plan['equivalence'],
            'resource_key' => $plan['resource_key'],
            'tenant_key' => $tenantKey,
            'row_count' => $result['count'],
        ]);

        return response()->json([
            'equivalence' => $plan['equivalence'],
            'confidence' => $plan['confidence'],
            'plan' => $plan,
            'fragments' => $plan['fragments'],
            'result' => $result,
        ]);
    }
}
