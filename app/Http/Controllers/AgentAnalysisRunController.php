<?php

namespace App\Http\Controllers;

use App\Enums\AgentAnalysisRunStatus;
use App\Http\Requests\StoreAgentAnalysisRunRequest;
use App\Jobs\RunAgentAnalysis;
use App\Models\AgentAnalysisRun;
use App\Models\Query;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Runs an agent analysis off the request cycle. The definition may be shared,
 * but the execution always uses an active Oracle connection belonging to the
 * reader; a run is only ever readable or cancellable by its own owner.
 */
class AgentAnalysisRunController extends Controller
{
    /**
     * Queue an agent analysis for a saved agent-mode query and return its run.
     */
    public function store(
        StoreAgentAnalysisRunRequest $request,
        Query $query,
        FusionManager $fusion,
        AuditRecorder $audit,
    ): JsonResponse {
        Gate::authorize('execute', $query);

        abort_unless(
            $query->mode === 'agent',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Seules les analyses agent s’exécutent de façon asynchrone.'),
        );

        $fusion = $fusion->forUser($request->user());
        $ownerPreferred = $query->user_id === $request->user()->id && $fusion->has($query->tenant_key)
            ? $query->tenant_key
            : null;
        $tenantKey = (string) ($request->validated()['tenant'] ?? $ownerPreferred ?? $fusion->defaultKey());

        abort_unless(
            $fusion->has($tenantKey),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Configurez une connexion Oracle active avant de lancer une analyse.'),
        );

        $tenant = $request->user()->oracleTenants()->where('key', $tenantKey)->first();

        $run = AgentAnalysisRun::create([
            'user_id' => $request->user()->id,
            'query_id' => $query->id,
            'oracle_tenant_id' => $tenant?->id,
            'status' => AgentAnalysisRunStatus::Queued,
            'queued_at' => now(),
        ]);

        RunAgentAnalysis::dispatch($run, $tenantKey);

        $audit->record($request->user(), 'query.agent_dispatched', $query, [
            'agent_analysis_run_id' => $run->id,
            'tenant_key' => $tenantKey,
        ]);

        return response()->json($this->toStatusPayload($run), Response::HTTP_ACCEPTED);
    }

    /**
     * Return the current state of a run so the owner can poll its progress.
     */
    public function show(Request $request, AgentAnalysisRun $agentAnalysisRun): JsonResponse
    {
        abort_unless(
            $agentAnalysisRun->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        return response()->json($this->toStatusPayload($agentAnalysisRun));
    }

    /**
     * Request cancellation. A run not yet started is resolved immediately; a
     * running one is stopped cooperatively by the job between two iterations.
     */
    public function cancel(
        Request $request,
        AgentAnalysisRun $agentAnalysisRun,
        AuditRecorder $audit,
    ): JsonResponse {
        abort_unless(
            $agentAnalysisRun->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        if ($agentAnalysisRun->status->isTerminal()) {
            return response()->json($this->toStatusPayload($agentAnalysisRun));
        }

        if ($agentAnalysisRun->status === AgentAnalysisRunStatus::Queued) {
            $agentAnalysisRun->forceFill([
                'status' => AgentAnalysisRunStatus::Cancelled,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
            ])->save();

            $audit->record($request->user(), 'query.agent_cancelled', $agentAnalysisRun->executedQuery, [
                'agent_analysis_run_id' => $agentAnalysisRun->id,
            ]);
        } else {
            $agentAnalysisRun->forceFill(['cancel_requested_at' => now()])->save();
        }

        return response()->json($this->toStatusPayload($agentAnalysisRun));
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
