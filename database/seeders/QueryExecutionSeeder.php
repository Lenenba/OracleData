<?php

namespace Database\Seeders;

use App\Models\OracleTenant;
use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\User;
use Illuminate\Database\Seeder;

class QueryExecutionSeeder extends Seeder
{
    /**
     * Seed execution history so the main Dashboard shows realistic statistics:
     * - queriesPerWeek sparkline
     * - successRate / averageDurationMs
     * - executionsThisMonth count
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();

        $adminTenant = $admin->oracleTenants()->where('is_default', true)->first();
        $analystTenant = $analyst->oracleTenants()->where('is_default', true)->first();
        $financeTenant = $finance->oracleTenants()->where('is_default', true)->first();

        $queries = Query::query()->whereIn('name', [
            'Bons de commande ouverts',
            'Factures fournisseurs à payer',
            "Demandes d'achat récentes",
            'Fournisseurs actifs avec sites',
            'Employés HCM actifs',
        ])->get()->keyBy('name');

        $runs = [
            // Each entry: [query_name, user, tenant, daysAgo, succeeded, rows, duration_ms]
            ['Bons de commande ouverts',         $admin,   $adminTenant,   0, true,  22, 380],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   1, true,  25, 420],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   2, false,  0, 510],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   3, true,  20, 350],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   4, true,  18, 390],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   5, true,  21, 400],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   6, true,  19, 370],
            ['Bons de commande ouverts',         $admin,   $adminTenant,   8, true,  24, 430],
            ['Bons de commande ouverts',         $admin,   $adminTenant,  12, true,  23, 410],
            ['Factures fournisseurs à payer',    $finance, $financeTenant,  0, true,  48, 620],
            ['Factures fournisseurs à payer',    $finance, $financeTenant,  1, true,  51, 590],
            ['Factures fournisseurs à payer',    $finance, $financeTenant,  3, false,  0, 810],
            ['Factures fournisseurs à payer',    $finance, $financeTenant,  7, true,  45, 640],
            ['Factures fournisseurs à payer',    $finance, $financeTenant, 14, true,  49, 600],
            ["Demandes d'achat récentes",        $analyst, $analystTenant,  0, true,  12, 290],
            ["Demandes d'achat récentes",        $analyst, $analystTenant,  1, true,  14, 310],
            ["Demandes d'achat récentes",        $analyst, $analystTenant,  2, true,  11, 280],
            ["Demandes d'achat récentes",        $analyst, $analystTenant,  4, true,  13, 295],
            ['Fournisseurs actifs avec sites',   $admin,   $adminTenant,   5, true,  87, 750],
            ['Fournisseurs actifs avec sites',   $admin,   $adminTenant,  10, false,  0, 990],
            ['Employés HCM actifs',              $analyst, $analystTenant,  2, true, 134, 480],
            ['Employés HCM actifs',              $analyst, $analystTenant,  9, true, 128, 460],
        ];

        // Avoid duplicates by checking existing count before inserting.
        if (QueryExecution::query()->count() > 0) {
            return;
        }

        foreach ($runs as [$queryName, $user, $tenant, $daysAgo, $succeeded, $rows, $durationMs]) {
            /** @var User $user */
            /** @var OracleTenant|null $tenant */
            $query = $queries->get($queryName);

            if (! $query instanceof Query) {
                continue;
            }

            $startedAt = now()->subDays($daysAgo)->setTime(rand(7, 18), rand(0, 59));
            $finishedAt = (clone $startedAt)->addMilliseconds($durationMs);

            QueryExecution::create([
                'query_id' => $query->id,
                'user_id' => $user->id,
                'oracle_tenant_id' => $tenant?->id,
                'source_type' => QueryExecution::SOURCE_SAVED_QUERY,
                'purpose' => QueryExecution::PURPOSE_RUN,
                'status' => $succeeded
                    ? QueryExecution::STATUS_SUCCEEDED
                    : QueryExecution::STATUS_FAILED,
                'duration_ms' => $durationMs,
                'rows_count' => $rows,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);
        }
    }
}
