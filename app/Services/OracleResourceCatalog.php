<?php

namespace App\Services;

/**
 * Catalogue des ressources Oracle Fusion REST accessibles en lecture.
 *
 * Source unique de vérité : sert à la fois de contexte fourni au LLM
 * (ressources et champs disponibles), de liste blanche de validation
 * (un champ/ressource hors catalogue est rejeté avant tout appel Oracle),
 * et de suggestions pour l'UI.
 *
 * @phpstan-type OracleResource array{
 *     key: string,
 *     label: string,
 *     description: string,
 *     domain: string,
 *     method: string,
 *     path: string,
 *     keywords: list<string>,
 *     fields: list<string>,
 *     preview_fields: list<string>,
 *     child_resources: list<string>,
 *     join_keys: array<string, string>
 * }
 */
class OracleResourceCatalog
{
    /**
     * Ressources Oracle Fusion REST déclarées (lecture seule, GET).
     *
     * @return list<OracleResource>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'suppliers',
                'label' => 'Fournisseurs',
                'description' => 'Fournisseurs Oracle Procurement, avec leurs sites, contacts et adresses.',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
                'keywords' => ['fournisseur', 'fournisseurs', 'supplier', 'suppliers', 'vendeur', 'vendor', 'achat', 'procurement'],
                'fields' => ['SupplierId', 'Supplier', 'SupplierNumber', 'Status', 'SupplierType', 'BusinessRelationship', 'TaxOrganizationType', 'CreationDate'],
                'preview_fields' => ['SupplierId', 'Supplier', 'SupplierNumber', 'Status'],
                'child_resources' => ['sites', 'contacts', 'addresses'],
                'join_keys' => ['invoices' => 'SupplierId', 'purchase_orders' => 'SupplierId'],
            ],
            [
                'key' => 'workers',
                'label' => 'Employés',
                'description' => 'Employés Oracle HCM (personnes et affectations).',
                'domain' => 'HCM',
                'method' => 'GET',
                'path' => '/hcmRestApi/resources/11.13.18.05/workers',
                'keywords' => ['employe', 'employes', 'employé', 'employés', 'worker', 'workers', 'collaborateur', 'personnel', 'salarie', 'salarié', 'rh'],
                'fields' => ['PersonId', 'PersonNumber', 'DisplayName', 'WorkEmail', 'HireDate', 'PersonType'],
                'preview_fields' => ['PersonId', 'PersonNumber', 'DisplayName', 'WorkEmail'],
                'child_resources' => ['assignments', 'addresses', 'emails', 'phones', 'names'],
                'join_keys' => [],
            ],
            [
                'key' => 'invoices',
                'label' => 'Factures fournisseurs',
                'description' => 'Factures fournisseurs Oracle Payables (AP).',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/invoices',
                'keywords' => ['facture', 'factures', 'invoice', 'invoices', 'payable', 'payables', 'comptes fournisseurs'],
                'fields' => ['InvoiceId', 'InvoiceNumber', 'InvoiceAmount', 'InvoiceDate', 'Supplier', 'SupplierId', 'InvoiceCurrency', 'PaymentStatus'],
                'preview_fields' => ['InvoiceId', 'InvoiceNumber', 'InvoiceAmount', 'InvoiceDate', 'Supplier'],
                'child_resources' => ['invoiceLines', 'invoiceInstallments'],
                'join_keys' => ['suppliers' => 'SupplierId'],
            ],
            [
                'key' => 'purchase_orders',
                'label' => 'Bons de commande',
                'description' => 'Bons de commande Oracle Procurement (PO).',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrders',
                'keywords' => ['bon de commande', 'bons de commande', 'commande', 'commandes', 'purchase order', 'purchase orders', 'po'],
                'fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'SupplierId', 'Status', 'CurrencyCode', 'CreationDate'],
                'preview_fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'Status'],
                'child_resources' => ['lines', 'schedules', 'distributions'],
                'join_keys' => ['suppliers' => 'SupplierId'],
            ],
        ];
    }

    /**
     * Clés de ressources connues (liste blanche).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (array $resource): string => $resource['key'], $this->all());
    }

    /**
     * Ressource par clé, ou null si inconnue.
     *
     * @return OracleResource|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $resource) {
            if ($resource['key'] === $key) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Suggestions allégées pour l'UI (création de requête).
     *
     * @return list<array<string, mixed>>
     */
    public function suggestions(): array
    {
        return array_map(
            fn (array $resource): array => $this->toSuggestion($resource),
            $this->all(),
        );
    }

    /**
     * Projection non sensible d'une ressource pour le front / les réponses JSON.
     *
     * @param  OracleResource  $resource
     * @return array<string, mixed>
     */
    public function toSuggestion(array $resource): array
    {
        return [
            'key' => $resource['key'],
            'label' => $resource['label'],
            'description' => $resource['description'],
            'domain' => $resource['domain'],
            'method' => $resource['method'],
            'path' => $resource['path'],
            'keywords' => $resource['keywords'],
            'preview_fields' => $resource['preview_fields'],
            'fields' => $resource['fields'],
            'child_resources' => $resource['child_resources'],
            'join_keys' => $resource['join_keys'],
        ];
    }

    /**
     * Description compacte du catalogue fournie au LLM comme contexte.
     */
    public function context(): string
    {
        $lines = [];

        foreach ($this->all() as $resource) {
            $lines[] = sprintf(
                "- %s (clé: %s, domaine: %s) — %s\n  champs: %s\n  enfants (expand): %s\n  jointures: %s",
                $resource['label'],
                $resource['key'],
                $resource['domain'],
                $resource['description'],
                implode(', ', $resource['fields']),
                $resource['child_resources'] === [] ? '(aucun)' : implode(', ', $resource['child_resources']),
                $resource['join_keys'] === []
                    ? '(aucune)'
                    : implode(', ', array_map(
                        fn (string $field, string $target): string => "{$target} via {$field}",
                        $resource['join_keys'],
                        array_keys($resource['join_keys']),
                    )),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Borne la limite de lignes dans [1, 500] (défaut 25).
     */
    public function clampLimit(mixed $limit): int
    {
        $parsed = is_numeric($limit) ? (int) $limit : 25;

        return min(500, max(1, $parsed));
    }
}
