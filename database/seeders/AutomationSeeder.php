<?php

namespace Database\Seeders;

use App\Enums\AlertCondition;
use App\Enums\ScheduleFrequency;
use App\Models\Query;
use App\Models\QueryAlert;
use App\Models\QuerySchedule;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Seeder;

class AutomationSeeder extends Seeder
{
    /**
     * Seed demo schedules, alerts and webhooks for the Automation settings page.
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();

        $poQuery = Query::query()->where('name', 'Bons de commande ouverts')->first();
        $invoiceQuery = Query::query()->where('name', 'Factures fournisseurs à payer')->first();
        $hcmQuery = Query::query()->where('name', 'Employés HCM actifs')->first();
        $reqQuery = Query::query()->where('name', "Demandes d'achat récentes")->first();

        // ── Schedules ────────────────────────────────────────────────────────
        $schedules = [];

        if ($poQuery) {
            $tenant = $admin->oracleTenants()->where('is_default', true)->first();

            $schedule = QuerySchedule::query()->firstOrCreate(
                ['user_id' => $admin->id, 'name' => 'PO ouverts — rapport quotidien'],
                [
                    'query_id' => $poQuery->id,
                    'oracle_tenant_id' => $tenant?->id,
                    'tenant_key' => $tenant->key ?? 'client_x',
                    'frequency' => ScheduleFrequency::Daily,
                    'time_of_day' => '07:00',
                    'day_of_week' => null,
                    'timezone' => 'Europe/Paris',
                    'is_active' => true,
                    'last_status' => QuerySchedule::STATUS_SUCCEEDED,
                    'last_run_at' => now()->subHours(2),
                    'next_run_at' => now()->addHours(22),
                ],
            );

            $schedules['po'] = $schedule;
        }

        if ($invoiceQuery) {
            $tenant = $finance->oracleTenants()->where('is_default', true)->first();

            $schedule = QuerySchedule::query()->firstOrCreate(
                ['user_id' => $finance->id, 'name' => 'Factures impayées — hebdomadaire'],
                [
                    'query_id' => $invoiceQuery->id,
                    'oracle_tenant_id' => $tenant?->id,
                    'tenant_key' => $tenant->key ?? 'client_x',
                    'frequency' => ScheduleFrequency::Weekly,
                    'time_of_day' => '08:00',
                    'day_of_week' => 1, // Monday
                    'timezone' => 'Europe/Paris',
                    'is_active' => true,
                    'last_status' => QuerySchedule::STATUS_SUCCEEDED,
                    'last_run_at' => now()->subDays(2),
                    'next_run_at' => now()->addDays(5),
                ],
            );

            $schedules['invoices'] = $schedule;
        }

        if ($hcmQuery) {
            $tenant = $analyst->oracleTenants()->where('is_default', true)->first();

            $schedule = QuerySchedule::query()->firstOrCreate(
                ['user_id' => $analyst->id, 'name' => 'Effectifs HCM — mensuel'],
                [
                    'query_id' => $hcmQuery->id,
                    'oracle_tenant_id' => $tenant?->id,
                    'tenant_key' => $tenant->key ?? 'client_x',
                    'frequency' => ScheduleFrequency::Daily,
                    'time_of_day' => '06:00',
                    'day_of_week' => null,
                    'timezone' => 'Europe/Paris',
                    'is_active' => false,
                    'last_status' => QuerySchedule::STATUS_FAILED,
                    'last_run_at' => now()->subDays(1),
                    'next_run_at' => now()->addDay(),
                ],
            );

            $schedules['hcm'] = $schedule;
        }

        if ($reqQuery) {
            $tenant = $analyst->oracleTenants()->where('is_default', true)->first();

            QuerySchedule::query()->firstOrCreate(
                ['user_id' => $analyst->id, 'name' => 'Réquisitions — toutes les heures'],
                [
                    'query_id' => $reqQuery->id,
                    'oracle_tenant_id' => $tenant?->id,
                    'tenant_key' => $tenant->key ?? 'client_x',
                    'frequency' => ScheduleFrequency::Hourly,
                    'time_of_day' => null,
                    'day_of_week' => null,
                    'timezone' => 'Europe/Paris',
                    'is_active' => true,
                    'last_status' => QuerySchedule::STATUS_SUCCEEDED,
                    'last_run_at' => now()->subMinutes(45),
                    'next_run_at' => now()->addMinutes(15),
                ],
            );
        }

        // ── Alerts ───────────────────────────────────────────────────────────
        if (isset($schedules['po'])) {
            QueryAlert::query()->firstOrCreate(
                ['query_schedule_id' => $schedules['po']->id, 'name' => 'Trop de PO ouverts'],
                [
                    'user_id' => $admin->id,
                    'condition' => AlertCondition::RowCountAbove,
                    'threshold' => 100,
                    'is_active' => true,
                ],
            );

            QueryAlert::query()->firstOrCreate(
                ['query_schedule_id' => $schedules['po']->id, 'name' => 'Exécution en échec'],
                [
                    'user_id' => $admin->id,
                    'condition' => AlertCondition::RunFailed,
                    'threshold' => null,
                    'is_active' => true,
                ],
            );
        }

        if (isset($schedules['invoices'])) {
            QueryAlert::query()->firstOrCreate(
                ['query_schedule_id' => $schedules['invoices']->id, 'name' => 'Factures impayées > 50'],
                [
                    'user_id' => $finance->id,
                    'condition' => AlertCondition::RowCountAbove,
                    'threshold' => 50,
                    'is_active' => true,
                    'last_triggered_at' => now()->subDays(3),
                ],
            );
        }

        // ── Webhooks ─────────────────────────────────────────────────────────
        WebhookEndpoint::query()->firstOrCreate(
            ['user_id' => $admin->id, 'name' => 'Slack — alertes critiques'],
            [
                'url' => 'https://hooks.slack.com/services/DEMO/DEMO/DEMO',
                'secret' => 'demo-secret-admin',
                'events' => ['schedule.run.failed', 'alert.triggered'],
                'is_active' => true,
                'last_delivered_at' => now()->subHours(3),
            ],
        );

        WebhookEndpoint::query()->firstOrCreate(
            ['user_id' => $finance->id, 'name' => 'Teams — rapport finance'],
            [
                'url' => 'https://outlook.office.com/webhook/DEMO',
                'secret' => 'demo-secret-finance',
                'events' => ['schedule.run.succeeded'],
                'is_active' => true,
                'last_delivered_at' => now()->subDays(2),
            ],
        );

        WebhookEndpoint::query()->firstOrCreate(
            ['user_id' => $analyst->id, 'name' => 'Endpoint inactif'],
            [
                'url' => 'https://example.com/webhook',
                'secret' => 'demo-secret-analyst',
                'events' => ['schedule.run.succeeded', 'schedule.run.failed'],
                'is_active' => false,
            ],
        );
    }
}
