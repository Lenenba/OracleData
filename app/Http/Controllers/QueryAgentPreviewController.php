<?php

namespace App\Http\Controllers;

use App\Enums\AgentAnalysisRunStatus;
use App\Jobs\RunAgentAnalysis;
use App\Models\AgentAnalysisRun;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lot 11E — Aperçu agent asynchrone depuis le builder.
 *
 * Crée un AgentAnalysisRun éphémère (query_id nullable) à partir d'une
 * intention brute, dispatch le job et renvoie l'ID pour le polling.
 *
 * Le suivi et l'annulation réutilisent les routes existantes
 * agent-runs.show / agent-runs.cancel.
 */
class QueryAgentPreviewController extends Controller
{
    /**
     * Dispatch an ephemeral agent analysis for a raw intent (no saved query required).
     */
    public function store(
        Request $request,
        FusionManager $fusion,
        AuditRecorder $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'intent' => ['required', 'string', 'min:10', 'max:2000'],
            'tenant_key' => ['nullable', 'string', 'max:100'],
        ]);

        $fusionForUser = $fusion->forUser($request->user());
        $tenantKey = (string) ($validated['tenant_key'] ?? $fusionForUser->defaultKey());

        abort_unless(
            $fusionForUser->has($tenantKey),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Configurez une connexion Oracle active avant de lancer une analyse.'),
        );

        $tenant = $request->user()->oracleTenants()->where('key', $tenantKey)->first();
        $maxIterations = max(1, (int) config('services.anthropic.max_iterations', 8));

        /** @var AgentAnalysisRun $run */
        $run = AgentAnalysisRun::create([
            'user_id' => $request->user()->id,
            'query_id' => null,
            'oracle_tenant_id' => $tenant?->id,
            'status' => AgentAnalysisRunStatus::Queued,
            'max_iterations' => $maxIterations,
            'queued_at' => now(),
        ]);

        // Pass the raw intent via the run's result field temporarily so the job
        // can read it without a saved query.  The job must handle query_id = null
        // gracefully using this intent override.
        $run->forceFill(['result' => ['_intent_override' => $validated['intent']]])->save();

        RunAgentAnalysis::dispatch($run, $tenantKey);

        $audit->record($request->user(), 'query.agent_preview_dispatched', $request->user(), [
            'agent_analysis_run_id' => $run->id,
            'tenant_key' => $tenantKey,
        ]);

        return response()->json($this->toStatusPayload($run), Response::HTTP_ACCEPTED);
    }

    /**
     * @return array<string, mixed>
     */
    private function toStatusPayload(AgentAnalysisRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'iteration' => $run->iteration,
            'max_iterations' => $run->max_iterations,
            'oracle_calls_count' => $run->oracle_calls_count,
            'row_count' => $run->row_count,
            'error_code' => $run->error_code,
            'result' => $run->result,
            'queued_at' => $run->queued_at->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
