<?php

namespace App\Jobs;

use App\Enums\AgentAnalysisRunStatus;
use App\Exceptions\AgentAnalysisCancelled;
use App\Models\AgentAnalysisRun;
use App\Models\Query;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\QueryAgent;
use App\Services\QueryExecutionRecorder;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs one agent analysis off the request cycle, keeping its {@see AgentAnalysisRun}
 * in sync so the owner can poll progress and cancel. Read-only: the tenant and
 * its credentials are resolved in the run owner's own scope, never the guard's.
 */
class RunAgentAnalysis implements ShouldQueue
{
    use Queueable;

    /**
     * Agent analyses are expensive and non-idempotent (LLM + Oracle reads), so
     * a failed attempt is reported, never silently retried.
     */
    public int $tries = 1;

    /**
     * Kept below the database queue `retry_after` (90s) so a stalled run is not
     * reclaimed and processed twice. A configurable ceiling is deferred to a
     * later lot of étape 9.
     */
    public int $timeout = 85;

    public function __construct(
        public AgentAnalysisRun $run,
        public string $tenantKey,
    ) {}

    public function handle(
        QueryAgent $agent,
        QueryExecutionRecorder $executions,
        AuditRecorder $audit,
    ): void {
        $run = $this->run->fresh();

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $user = $run->user;
        $query = $run->executedQuery;

        // A cancellation requested before the worker picked the job up resolves
        // immediately, without any Oracle or LLM call.
        if ($run->isCancellationRequested()) {
            $this->markCancelled($run, $user, $query, $audit);

            return;
        }

        $startedAt = now();
        $startedAtNs = hrtime(true);
        $run->forceFill([
            'status' => AgentAnalysisRunStatus::Running,
            'started_at' => $startedAt,
        ])->save();

        try {
            $result = $agent->forUser($user)->run(
                $this->tenantKey,
                (string) ($query->description ?? ''),
                onProgress: function (int $iteration, int $oracleCalls) use ($run): void {
                    $run->forceFill([
                        'iteration' => $iteration,
                        'oracle_calls_count' => $oracleCalls,
                    ])->save();
                },
                shouldCancel: fn (): bool => AgentAnalysisRun::query()
                    ->whereKey($run->id)
                    ->whereNotNull('cancel_requested_at')
                    ->exists(),
            );
        } catch (AgentAnalysisCancelled) {
            $this->markCancelled($run, $user, $query, $audit);

            return;
        } catch (InvalidArgumentException|RuntimeException) {
            $this->markFailed($run, $user, $query, $executions, $audit, $startedAt, $startedAtNs);

            return;
        }

        $this->markCompleted($run, $user, $query, $executions, $audit, $result, $startedAt, $startedAtNs);
    }

    /**
     * Last-resort net for a timeout or fatal crash the handler could not catch.
     */
    public function failed(?Throwable $exception): void
    {
        $run = $this->run->fresh();

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => AgentAnalysisRunStatus::Failed,
            'error_code' => 'agent_error',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @param  array{columns: list<string>, rows: array<int, mixed>, analysis: string, oracleCalls: list<array{resource: string, params: array<string, mixed>, count: int}>}  $result
     */
    private function markCompleted(
        AgentAnalysisRun $run,
        User $user,
        Query $query,
        QueryExecutionRecorder $executions,
        AuditRecorder $audit,
        array $result,
        CarbonInterface $startedAt,
        float $startedAtNs,
    ): void {
        $rows = array_values($result['rows']);
        $rowCount = count($rows);
        $durationMs = $this->durationMs($startedAtNs);
        $storedResult = $this->normalizedResult($result, $rows, $rowCount, null);

        $execution = $executions->recordQueryRun(
            $user,
            $query,
            $this->tenantKey,
            array_replace($storedResult, ['items' => $rows]),
            $startedAt,
            $durationMs,
        );

        $run->forceFill([
            'status' => AgentAnalysisRunStatus::Completed,
            'query_execution_id' => $execution->id,
            'oracle_tenant_id' => $execution->oracle_tenant_id,
            'auth_connection_id' => $execution->auth_connection_id,
            'result' => $storedResult,
            'row_count' => $rowCount,
            'finished_at' => now(),
        ])->save();

        $audit->record($user, 'query.agent_completed', $query, [
            'agent_analysis_run_id' => $run->id,
            'query_execution_id' => $execution->id,
            'tenant_key' => $this->tenantKey,
            'duration_ms' => $durationMs,
            'row_count' => $rowCount,
        ]);
    }

    private function markFailed(
        AgentAnalysisRun $run,
        User $user,
        Query $query,
        QueryExecutionRecorder $executions,
        AuditRecorder $audit,
        CarbonInterface $startedAt,
        float $startedAtNs,
    ): void {
        $durationMs = $this->durationMs($startedAtNs);
        $execution = $executions->recordQueryRun(
            $user,
            $query,
            $this->tenantKey,
            ['items' => [], 'oracleCalls' => [], 'error' => __('La validation Oracle a échoué.')],
            $startedAt,
            $durationMs,
        );

        $run->forceFill([
            'status' => AgentAnalysisRunStatus::Failed,
            'query_execution_id' => $execution->id,
            'oracle_tenant_id' => $execution->oracle_tenant_id,
            'auth_connection_id' => $execution->auth_connection_id,
            'error_code' => 'agent_error',
            'finished_at' => now(),
        ])->save();

        $audit->record($user, 'query.agent_failed', $query, [
            'agent_analysis_run_id' => $run->id,
            'query_execution_id' => $execution->id,
            'tenant_key' => $this->tenantKey,
            'error_code' => 'agent_error',
        ]);
    }

    private function markCancelled(
        AgentAnalysisRun $run,
        User $user,
        Query $query,
        AuditRecorder $audit,
    ): void {
        $run->forceFill([
            'status' => AgentAnalysisRunStatus::Cancelled,
            'cancel_requested_at' => $run->cancel_requested_at ?? now(),
            'finished_at' => now(),
        ])->save();

        $audit->record($user, 'query.agent_cancelled', $query, [
            'agent_analysis_run_id' => $run->id,
            'tenant_key' => $this->tenantKey,
        ]);
    }

    /**
     * Normalized, capped result payload shared by storage and the status
     * endpoint, so a completed run renders exactly like a synchronous one.
     *
     * @param  array{columns: list<string>, rows: array<int, mixed>, analysis: string, oracleCalls: list<array{resource: string, params: array<string, mixed>, count: int}>}  $result
     * @param  array<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private function normalizedResult(array $result, array $rows, int $rowCount, ?string $error): array
    {
        return [
            'mode' => 'agent',
            'tenant' => $this->tenantKey,
            'resource' => null,
            'parameters' => null,
            'columns' => $result['columns'],
            'analysis' => $result['analysis'],
            'items' => array_slice($rows, 0, AgentAnalysisRun::MAX_RESULT_ROWS),
            'count' => $rowCount,
            'hasMore' => false,
            'oracleCalls' => $result['oracleCalls'],
            'clarification' => null,
            'error' => $error,
            'truncated' => $rowCount > AgentAnalysisRun::MAX_RESULT_ROWS,
        ];
    }

    private function durationMs(float $startedAtNs): int
    {
        return max(0, (int) round((hrtime(true) - $startedAtNs) / 1_000_000));
    }
}
