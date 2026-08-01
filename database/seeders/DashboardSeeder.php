<?php

namespace Database\Seeders;

use App\Models\Query;
use App\Models\QueryDashboard;
use App\Models\User;
use Illuminate\Database\Seeder;

class DashboardSeeder extends Seeder
{
    /**
     * Seed demo dashboards with widgets that point at real seeded queries.
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();

        // Resolve seeded queries by name to avoid hard-coded IDs.
        $poQuery = Query::query()->where('name', 'Bons de commande ouverts')->first();
        $invoiceQuery = Query::query()->where('name', 'Factures fournisseurs à payer')->first();
        $suppQuery = Query::query()->where('name', 'Fournisseurs actifs avec sites')->first();
        $hcmQuery = Query::query()->where('name', 'Employés HCM actifs')->first();
        $reqQuery = Query::query()->where('name', "Demandes d'achat récentes")->first();

        // ── Tableau de bord Pilotage Achats (admin) ─────────────────────────
        $achats = QueryDashboard::query()->firstOrCreate(
            ['user_id' => $admin->id, 'name' => 'Pilotage Achats'],
            ['description' => 'Vue consolidée des bons de commande ouverts et des fournisseurs actifs.'],
        );

        if ($achats->widgets()->count() === 0 && $poQuery && $suppQuery) {
            $achats->widgets()->createMany([
                [
                    'query_id' => $poQuery->id,
                    'widget_type' => 'kpi',
                    'title' => 'Bons de commande ouverts',
                    'position' => 1,
                    'widget_options' => ['column' => 'Ordered'],
                ],
                [
                    'query_id' => $poQuery->id,
                    'widget_type' => 'table',
                    'title' => 'Détail des PO ouverts',
                    'position' => 2,
                    'widget_options' => ['limit' => 10],
                ],
                [
                    'query_id' => $suppQuery->id,
                    'widget_type' => 'chart',
                    'title' => 'Fournisseurs actifs',
                    'position' => 3,
                    'widget_options' => ['chart_type' => 'bar'],
                ],
            ]);
        }

        // ── Tableau de bord Finance (finance) ────────────────────────────────
        $financeDash = QueryDashboard::query()->firstOrCreate(
            ['user_id' => $finance->id, 'name' => 'Suivi Facturation'],
            ['description' => 'Indicateurs clés des factures fournisseurs non payées.'],
        );

        if ($financeDash->widgets()->count() === 0 && $invoiceQuery) {
            $financeDash->widgets()->createMany([
                [
                    'query_id' => $invoiceQuery->id,
                    'widget_type' => 'kpi',
                    'title' => 'Total à payer',
                    'position' => 1,
                    'widget_options' => ['column' => 'InvoiceAmount'],
                ],
                [
                    'query_id' => $invoiceQuery->id,
                    'widget_type' => 'table',
                    'title' => 'Factures en attente',
                    'position' => 2,
                    'widget_options' => ['limit' => 15],
                ],
            ]);
        }

        // ── Tableau de bord RH (analyst) ─────────────────────────────────────
        $hrDash = QueryDashboard::query()->firstOrCreate(
            ['user_id' => $analyst->id, 'name' => 'Tableau RH'],
            ['description' => 'Effectifs actifs et demandes d\'achat récentes.'],
        );

        if ($hrDash->widgets()->count() === 0 && $hcmQuery && $reqQuery) {
            $hrDash->widgets()->createMany([
                [
                    'query_id' => $hcmQuery->id,
                    'widget_type' => 'kpi',
                    'title' => 'Employés actifs',
                    'position' => 1,
                    'widget_options' => ['column' => 'PersonId'],
                ],
                [
                    'query_id' => $reqQuery->id,
                    'widget_type' => 'table',
                    'title' => 'Demandes d\'achat récentes',
                    'position' => 2,
                    'widget_options' => ['limit' => 10],
                ],
                [
                    'query_id' => $reqQuery->id,
                    'widget_type' => 'chart',
                    'title' => 'Tendance des réquisitions',
                    'position' => 3,
                    'widget_options' => ['chart_type' => 'line'],
                ],
            ]);
        }

        // ── Tableau de bord vide (pour tester l'état empty) ──────────────────
        QueryDashboard::query()->firstOrCreate(
            ['user_id' => $admin->id, 'name' => 'Vue Exécutive'],
            ['description' => 'Espace réservé pour les indicateurs de direction.'],
        );
    }
}
