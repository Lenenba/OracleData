<?php

namespace App\Services;

use App\Enums\AlertCondition;
use App\Models\QueryAlert;
use App\Models\QueryAlertEvent;
use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Notifications\QueryAlertNotification;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates a schedule's active alerts against one finished run and, for each
 * match, records an immutable event and notifies the owner. Notifications carry
 * technical identifiers only; the human message is resolved server-side.
 */
class QueryAlertEvaluator
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function evaluate(QuerySchedule $schedule, QueryScheduleRun $run): void
    {
        $alerts = $schedule->alerts()->where('is_active', true)->get();

        foreach ($alerts as $alert) {
            if ($this->matches($alert, $run)) {
                $this->trigger($schedule, $alert, $run);
            }
        }
    }

    private function matches(QueryAlert $alert, QueryScheduleRun $run): bool
    {
        $succeeded = $run->status === QuerySchedule::STATUS_SUCCEEDED;

        return match ($alert->condition) {
            AlertCondition::RunFailed => $run->status === QuerySchedule::STATUS_FAILED,
            AlertCondition::RowCountAbove => $succeeded && $run->row_count > (int) $alert->threshold,
            AlertCondition::RowCountBelow => $succeeded && $run->row_count < (int) $alert->threshold,
        };
    }

    private function trigger(QuerySchedule $schedule, QueryAlert $alert, QueryScheduleRun $run): void
    {
        $observed = $run->status === QuerySchedule::STATUS_SUCCEEDED ? $run->row_count : null;

        $event = DB::transaction(function () use ($schedule, $alert, $run, $observed): QueryAlertEvent {
            $event = QueryAlertEvent::query()->create([
                'query_alert_id' => $alert->id,
                'query_schedule_run_id' => $run->id,
                'user_id' => $alert->user_id,
                'observed_value' => $observed,
                'triggered_at' => now(),
            ]);

            $alert->forceFill(['last_triggered_at' => now()])->save();

            $alert->user->notify(new QueryAlertNotification(
                $alert->id,
                $schedule->id,
                $event->id,
                $schedule->query_id,
                $alert->condition->value,
                $observed,
            ));

            $this->audit->record($alert->user, 'query.alert_triggered', $schedule->executedQuery, [
                'query_alert_id' => $alert->id,
                'query_schedule_id' => $schedule->id,
                'query_alert_event_id' => $event->id,
                'query_schedule_run_id' => $run->id,
                'condition' => $alert->condition->value,
                'observed_value' => $observed,
            ]);

            return $event;
        });

        // Outbound delivery happens only after the event is durably committed.
        $this->webhooks->dispatchAlertEvent($schedule, $alert, $event);
    }
}
