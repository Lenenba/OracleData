<?php

namespace Database\Seeders;

use App\Enums\QueryChangeRequestStatus;
use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChangeRequestSeeder extends Seeder
{
    /**
     * Seed demo change-requests with comments for the Change Requests pages.
     * One per lifecycle state so every UI path is exercised.
     */
    public function run(): void
    {
        $admin   = User::query()->where('email', 'test@example.com')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();

        $poQuery      = Query::query()->where('name', 'Bons de commande ouverts')->first();
        $invoiceQuery = Query::query()->where('name', 'Factures fournisseurs à payer')->first();
        $suppQuery    = Query::query()->where('name', 'Fournisseurs actifs avec sites')->first();
        $hcmQuery     = Query::query()->where('name', 'Employés HCM actifs')->first();
        $reqQuery     = Query::query()->where('name', "Demandes d'achat récentes")->first();

        // ── PENDING — awaiting owner decision ────────────────────────────────
        if ($poQuery) {
            $cr = QueryChangeRequest::query()->firstOrCreate(
                [
                    'query_id'              => $poQuery->id,
                    'requested_by_user_id'  => $analyst->id,
                    'title'                 => 'Ajouter le champ "Description" aux PO',
                ],
                ['status' => QueryChangeRequestStatus::PENDING],
            );

            $cr->comments()->firstOrCreate(
                ['user_id' => $analyst->id, 'body' => "Bonjour, serait-il possible d'inclure le champ Description dans les colonnes retournées ? Cela faciliterait les contrôles."],
            );
        }

        // ── ACCEPTED — owner approved, work in progress ──────────────────────
        if ($invoiceQuery) {
            $cr = QueryChangeRequest::query()
                ->where('query_id', $invoiceQuery->id)
                ->where('status', QueryChangeRequestStatus::ACCEPTED->value)
                ->first();

            if (! $cr) {
                $cr = QueryChangeRequest::create([
                    'query_id'             => $invoiceQuery->id,
                    'requested_by_user_id' => $analyst->id,
                    'title'                => 'Filtrer aussi sur la devise EUR',
                    'status'               => QueryChangeRequestStatus::PENDING,
                ]);

                $cr->transitionTo(QueryChangeRequestStatus::ACCEPTED, $finance);
            }

            $cr->comments()->firstOrCreate(
                ['user_id' => $finance->id, 'body' => "Demande acceptée. Je vais ajouter le filtre currency='EUR' dans les paramètres."],
            );
        }

        // ── COMPLETED ────────────────────────────────────────────────────────
        if ($suppQuery) {
            $cr = QueryChangeRequest::query()
                ->where('query_id', $suppQuery->id)
                ->where('status', QueryChangeRequestStatus::COMPLETED->value)
                ->first();

            if (! $cr) {
                $cr = QueryChangeRequest::create([
                    'query_id'             => $suppQuery->id,
                    'requested_by_user_id' => $finance->id,
                    'title'                => 'Inclure PrimaryPaySite dans les sites',
                    'status'               => QueryChangeRequestStatus::PENDING,
                ]);

                $cr->transitionTo(QueryChangeRequestStatus::ACCEPTED, $admin);
                $cr->transitionTo(QueryChangeRequestStatus::COMPLETED, $admin);
            }

            $cr->comments()->firstOrCreate(
                ['user_id' => $admin->id, 'body' => "Modification appliquée — PrimaryPaySite ajouté dans child_fields.sites."],
            );
        }

        // ── REJECTED ─────────────────────────────────────────────────────────
        if ($hcmQuery) {
            $cr = QueryChangeRequest::query()
                ->where('query_id', $hcmQuery->id)
                ->where('status', QueryChangeRequestStatus::REJECTED->value)
                ->first();

            if (! $cr) {
                $cr = QueryChangeRequest::create([
                    'query_id'             => $hcmQuery->id,
                    'requested_by_user_id' => $finance->id,
                    'title'                => 'Étendre la limite à 500 lignes',
                    'status'               => QueryChangeRequestStatus::PENDING,
                ]);

                $cr->transitionTo(QueryChangeRequestStatus::REJECTED, $analyst);
            }

            $cr->comments()->firstOrCreate(
                ['user_id' => $analyst->id, 'body' => "La performance serait dégradée sur un tenant avec un grand nombre d'employés. Utiliser la pagination à la place."],
            );
        }

        // ── CANCELLED ────────────────────────────────────────────────────────
        if ($reqQuery) {
            $cr = QueryChangeRequest::query()
                ->where('query_id', $reqQuery->id)
                ->where('status', QueryChangeRequestStatus::CANCELLED->value)
                ->first();

            if (! $cr) {
                $cr = QueryChangeRequest::create([
                    'query_id'             => $reqQuery->id,
                    'requested_by_user_id' => $analyst->id,
                    'title'                => 'Changer le tri par DocumentStatus',
                    'status'               => QueryChangeRequestStatus::PENDING,
                ]);

                $cr->transitionTo(QueryChangeRequestStatus::CANCELLED, $analyst);
            }
        }
    }
}
