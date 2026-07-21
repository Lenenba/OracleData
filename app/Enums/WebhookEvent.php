<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case AlertTriggered   = 'query.alert_triggered';
    /** Lot 12D — emis quand une exécution de requête se termine avec succès. */
    case RunCompleted     = 'query.run_completed';
    /** Lot 12D — emis quand un export serveur est disponible au téléchargement. */
    case ExportReady      = 'query.export_ready';
    /** Lot 12D — emis quand une analyse agent se termine avec succès. */
    case AgentCompleted   = 'query.agent_completed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
