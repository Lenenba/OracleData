<?php

namespace App\Services;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\AgentAnalysisRun;
use App\Models\QueryAlert;
use App\Models\QueryAlertEvent;
use App\Models\QueryExecution;
use App\Models\QueryExport;
use App\Models\QuerySchedule;
use App\Models\WebhookEndpoint;

/**
 * Fans one internal event out to every active endpoint of the owning user that
 * subscribes to it. Payloads carry technical metadata only, never Oracle data.
 */
class WebhookDispatcher
{
    public function dispatchAlertEvent(
        QuerySchedule $schedule,
        QueryAlert $alert,
        QueryAlertEvent $event,
    ): void {
        $this->dispatch($alert->user_id, WebhookEvent::AlertTriggered->value, [
            'event' => WebhookEvent::AlertTriggered->value,
            'alert' => [
                'id' => $alert->id,
                'name' => $alert->name,
                'condition' => $alert->condition->value,
                'threshold' => $alert->threshold,
            ],
            'schedule' => [
                'id' => $schedule->id,
                'name' => $schedule->name,
            ],
            'query' => ['id' => $schedule->query_id],
            'observed_value' => $event->observed_value,
            'triggered_at' => $event->triggered_at->toIso8601String(),
        ]);
    }

    /**
     * Lot 12D — emis après une exécution de requête terminée avec succès.
     */
    public function dispatchRunCompleted(QueryExecution $execution): void
    {
        $this->dispatch($execution->user_id, WebhookEvent::RunCompleted->value, [
            'event' => WebhookEvent::RunCompleted->value,
            'execution_id' => $execution->id,
            'query_id' => $execution->query_id,
            'tenant_key' => $execution->oracleTenant?->key,
            'rows_count' => $execution->rows_count,
            'duration_ms' => $execution->duration_ms,
            'finished_at' => $execution->finished_at->toIso8601String(),
        ]);
    }

    /**
     * Lot 12D — emis quand un export serveur est disponible au téléchargement.
     */
    public function dispatchExportReady(QueryExport $export): void
    {
        $this->dispatch($export->user_id, WebhookEvent::ExportReady->value, [
            'event' => WebhookEvent::ExportReady->value,
            'export_id' => $export->id,
            'query_id' => $export->query_id,
            'format' => $export->format,
            'row_count' => $export->row_count,
            'file_size' => $export->file_size,
            'expires_at' => $export->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Lot 12D — emis quand une analyse agent se termine avec succès.
     */
    public function dispatchAgentCompleted(AgentAnalysisRun $run): void
    {
        $this->dispatch($run->user_id, WebhookEvent::AgentCompleted->value, [
            'event' => WebhookEvent::AgentCompleted->value,
            'run_id' => $run->id,
            'query_id' => $run->query_id,
            'iteration' => $run->iteration,
            'oracle_calls_count' => $run->oracle_calls_count,
            'row_count' => $run->row_count,
            'confidence' => $run->result['confidence'] ?? null,
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(int $userId, string $event, array $payload): void
    {
        WebhookEndpoint::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event))
            ->each(fn (WebhookEndpoint $endpoint) => DeliverWebhook::dispatch($endpoint, $event, $payload));
    }
}
