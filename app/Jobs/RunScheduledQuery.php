<?php

namespace App\Jobs;

use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Services\FusionManager;
use App\Services\NextRunCalculator;
use App\Services\OracleQueryTool;
use App\Services\QueryAlertEvaluator;
use App\Services\QueryExportRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs one scheduled query unattended, in the owner's own connection scope, and
 * records the timestamped outcome. Read-only; never resolves another user's
 * credentials or a global fallback.
 */
class RunScheduledQuery implements ShouldQueue
{
    use Queueable;

    /** Scheduled runs are re-created on the next tick, so a failure is recorded, not retried. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public QuerySchedule $schedule) {}

    public function handle(
        FusionManager $fusion,
        OracleQueryTool $tool,
        QueryExportRunner $runner,
        NextRunCalculator $calculator,
        QueryAlertEvaluator $alerts,
    ): void {
        $schedule = $this->schedule->fresh();

        if ($schedule === null || ! $schedule->is_active) {
            return;
        }

        $user = $schedule->user;
        $query = $schedule->executedQuery;
        $ranAt = now();
        $startedAtNs = hrtime(true);
        $status = QuerySchedule::STATUS_SUCCEEDED;
        $rowCount = 0;
        $errorCode = null;
        $tenantId = null;
        $connectionId = null;

        try {
            $scopedFusion = $fusion->forUser($user);

            if (! $scopedFusion->has($schedule->tenant_key)) {
                throw new RuntimeException('connection_unavailable');
            }

            $limit = (int) ($query->parameters['limit'] ?? 25);
            $page = $runner->page(
                $scopedFusion,
                $tool->forUser($user),
                $query,
                $schedule->tenant_key,
                0,
                max(1, $limit),
            );
            $rowCount = count($page['items']);

            $tenant = $user->oracleTenants()->where('key', $schedule->tenant_key)->first();
            $tenantId = $tenant?->id;
            $connectionId = $tenant?->authConnections()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');
        } catch (InvalidArgumentException|RuntimeException) {
            $status = QuerySchedule::STATUS_FAILED;
            $errorCode = 'oracle_error';
        }

        $run = QueryScheduleRun::create([
            'query_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'oracle_tenant_id' => $tenantId,
            'auth_connection_id' => $connectionId,
            'status' => $status,
            'row_count' => $rowCount,
            'duration_ms' => max(0, (int) round((hrtime(true) - $startedAtNs) / 1_000_000)),
            'error_code' => $errorCode,
            'ran_at' => $ranAt,
        ]);

        $schedule->forceFill([
            'last_run_at' => $ranAt,
            'last_status' => $status,
            'next_run_at' => $calculator->next($schedule, $ranAt),
        ])->save();

        $alerts->evaluate($schedule, $run);
    }
}
