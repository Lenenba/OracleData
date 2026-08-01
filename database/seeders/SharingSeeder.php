<?php

namespace Database\Seeders;

use App\Enums\QuerySharePermission;
use App\Models\Group;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\QueryUserShare;
use App\Models\SavedQueryView;
use App\Models\User;
use Illuminate\Database\Seeder;

class SharingSeeder extends Seeder
{
    /**
     * Seed user shares, group shares and saved query views.
     * Drives the Sharing, Groups and Library pages with realistic data.
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();

        // Queries we want to share across users.
        $poQuery = Query::query()->where('name', 'Bons de commande ouverts')->first();
        $invoiceQuery = Query::query()->where('name', 'Factures fournisseurs à payer')->first();
        $agentQuery = Query::query()->where('name', 'Analyse fournisseurs et factures')->first();

        // Resolve groups.
        $financeGroup = Group::query()->where('name', 'Équipe Finance')->first();
        $analyticsGroup = Group::query()->where('name', 'Analystes Oracle')->first();

        // ── Direct user shares ───────────────────────────────────────────────

        // admin shares "Bons de commande ouverts" with analyst — execute
        if ($poQuery) {
            QueryUserShare::query()->firstOrCreate(
                [
                    'query_id' => $poQuery->id,
                    'user_id' => $analyst->id,
                ],
                [
                    'shared_by_user_id' => $admin->id,
                    'permission' => QuerySharePermission::EXECUTE,
                    'status' => QueryUserShare::STATUS_ACCEPTED,
                    'accepted_at' => now()->subDays(7),
                    'respond_by' => null,
                ],
            );
        }

        // admin shares "Analyse fournisseurs et factures" with finance — view
        if ($agentQuery) {
            QueryUserShare::query()->firstOrCreate(
                [
                    'query_id' => $agentQuery->id,
                    'user_id' => $finance->id,
                ],
                [
                    'shared_by_user_id' => $admin->id,
                    'permission' => QuerySharePermission::VIEW,
                    'status' => QueryUserShare::STATUS_ACCEPTED,
                    'accepted_at' => now()->subDays(3),
                    'respond_by' => null,
                ],
            );
        }

        // finance shares "Factures fournisseurs à payer" with analyst — pending invitation
        if ($invoiceQuery) {
            $existing = QueryUserShare::query()
                ->where('query_id', $invoiceQuery->id)
                ->where('user_id', $analyst->id)
                ->where('status', QueryUserShare::STATUS_PENDING)
                ->first();

            if (! $existing) {
                QueryUserShare::create([
                    'query_id' => $invoiceQuery->id,
                    'user_id' => $analyst->id,
                    'shared_by_user_id' => $finance->id,
                    'permission' => QuerySharePermission::CLONE,
                    'status' => QueryUserShare::STATUS_PENDING,
                    'respond_by' => now()->addDays(7),
                ]);
            }
        }

        // ── Group shares ─────────────────────────────────────────────────────

        // "Bons de commande ouverts" visible by Équipe Finance group
        if ($poQuery && $financeGroup) {
            $exists = QueryGroupShare::query()
                ->where('query_id', $poQuery->id)
                ->where('group_id', $financeGroup->id)
                ->where('status', QueryGroupShare::STATUS_ACCEPTED)
                ->exists();

            if (! $exists) {
                QueryGroupShare::create([
                    'query_id' => $poQuery->id,
                    'group_id' => $financeGroup->id,
                    'group_name' => $financeGroup->name,
                    'shared_by_user_id' => $admin->id,
                    'permission' => QuerySharePermission::EXECUTE,
                    'status' => QueryGroupShare::STATUS_ACCEPTED,
                ]);
            }
        }

        // "Analyse fournisseurs et factures" visible by Analystes Oracle group
        if ($agentQuery && $analyticsGroup) {
            $exists = QueryGroupShare::query()
                ->where('query_id', $agentQuery->id)
                ->where('group_id', $analyticsGroup->id)
                ->where('status', QueryGroupShare::STATUS_ACCEPTED)
                ->exists();

            if (! $exists) {
                QueryGroupShare::create([
                    'query_id' => $agentQuery->id,
                    'group_id' => $analyticsGroup->id,
                    'group_name' => $analyticsGroup->name,
                    'shared_by_user_id' => $admin->id,
                    'permission' => QuerySharePermission::VIEW,
                    'status' => QueryGroupShare::STATUS_ACCEPTED,
                ]);
            }
        }

        // ── Saved query views (library filter presets) ───────────────────────
        $this->seedSavedViews($admin);
        $this->seedSavedViews($analyst);
        $this->seedSavedViews($finance);
    }

    private function seedSavedViews(User $user): void
    {
        SavedQueryView::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Mes requêtes Finance'],
            [
                'filters' => [
                    'category' => 'finance',
                    'mode' => 'single',
                    'mine' => 'true',
                ],
                'is_default' => false,
            ],
        );

        SavedQueryView::query()->firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Tout le catalogue'],
            [
                'filters' => [
                    'access_level' => 'organization',
                ],
                'is_default' => true,
            ],
        );
    }
}
