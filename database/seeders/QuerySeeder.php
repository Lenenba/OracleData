<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Query;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class QuerySeeder extends Seeder
{
    /**
     * Seed a practical query library for the local OracleData workspace.
     */
    public function run(): void
    {
        $owner = User::query()->where('email', 'test@example.com')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();

        $this->upsertQuery($owner, [
            'name' => 'Bons de commande ouverts',
            'description' => 'PO ouverts avec fournisseur, statut et montant commandé.',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrders',
            'tenant_key' => 'client_x',
            'mode' => 'single',
            'parameters' => [
                'resource_key' => 'purchase_orders',
                'fields' => 'POHeaderId,OrderNumber,Supplier,Status,Ordered,CreationDate',
                'q' => "Status!='Closed'",
                'orderBy' => 'CreationDate:desc',
                'limit' => 25,
            ],
            'access_level' => 'organization',
            'category_slug' => 'achats',
            'tags' => ['mensuel', 'tableau-de-bord'],
        ]);

        $this->upsertQuery($owner, [
            'name' => 'Fournisseurs actifs avec sites',
            'description' => 'Fournisseurs actifs et leurs sites d’achat principaux.',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
            'tenant_key' => 'client_x',
            'mode' => 'single',
            'parameters' => [
                'resource_key' => 'suppliers',
                'fields' => 'Supplier,SupplierNumber,Status',
                'expand' => 'sites',
                'child_fields' => [
                    'sites' => ['SupplierSite', 'PurchasingEnabled', 'PrimaryPaySite'],
                ],
                'q' => "Status='ACTIVE'",
                'limit' => 50,
            ],
            'access_level' => 'private',
            'category_slug' => 'fournisseurs',
            'tags' => ['audit'],
        ]);

        $this->upsertQuery($analyst, [
            'name' => 'Demandes d’achat récentes',
            'description' => 'Demandes d’achat avec statut et préparateur.',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/purchaseRequisitions',
            'tenant_key' => 'client_x',
            'mode' => 'single',
            'parameters' => [
                'resource_key' => 'purchase_requisitions',
                'fields' => 'RequisitionHeaderId,RequisitionNumber,Preparer,DocumentStatus,CreationDate',
                'orderBy' => 'CreationDate:desc',
                'limit' => 25,
            ],
            'access_level' => 'organization',
        ]);

        $this->upsertQuery($finance, [
            'name' => 'Factures fournisseurs à payer',
            'description' => 'Factures AP non payées avec fournisseur et montant.',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
            'tenant_key' => 'client_x',
            'mode' => 'single',
            'parameters' => [
                'resource_key' => 'invoices',
                'fields' => 'InvoiceId,InvoiceNumber,Supplier,InvoiceAmount,PaidStatus,InvoiceDate',
                'q' => "PaidStatus!='Y'",
                'orderBy' => 'InvoiceDate:desc',
                'limit' => 25,
            ],
            'access_level' => 'organization',
            'category_slug' => 'finance',
            'tags' => ['mensuel', 'reglementaire'],
        ]);

        $this->upsertQuery($finance, [
            'name' => 'Analyse fournisseurs et factures',
            'description' => 'Lier les fournisseurs et les factures, puis résumer le total facturé par fournisseur.',
            'resource_path' => null,
            'tenant_key' => 'client_x',
            'mode' => 'agent',
            'parameters' => null,
            'access_level' => 'organization',
        ]);

        $this->upsertQuery($analyst, [
            'name' => 'Employés HCM actifs',
            'description' => 'Base HCM workers pour contrôles RH.',
            'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
            'tenant_key' => 'client_x',
            'mode' => 'single',
            'parameters' => [
                'resource_key' => 'workers',
                'fields' => 'PersonId,PersonNumber,CreationDate',
                'limit' => 25,
            ],
            'access_level' => 'private',
            'category_slug' => 'ressources-humaines',
            'tags' => ['annuel'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertQuery(User $user, array $attributes): void
    {
        $categorySlug = Arr::pull($attributes, 'category_slug');
        /** @var list<string> $tagSlugs */
        $tagSlugs = (array) Arr::pull($attributes, 'tags', []);

        $tenantKey = (string) ($attributes['tenant_key'] ?? '');
        $attributes['oracle_tenant_id'] = $user->oracleTenants()
            ->where('key', $tenantKey)
            ->value('id');

        if ($categorySlug !== null) {
            $attributes['category_id'] = Category::query()
                ->where('slug', $categorySlug)
                ->value('id');
        }

        $query = Query::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'name' => $attributes['name'],
            ],
            $attributes,
        );

        if ($tagSlugs !== []) {
            $tagIds = Tag::query()->whereIn('slug', $tagSlugs)->pluck('id');
            $query->tags()->sync($tagIds);
        }
    }
}
