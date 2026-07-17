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
 * @phpstan-type JoinKey array{local_key: string, remote_key: string, label: string}
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
 *     child_fields: array<string, list<string>>,
 *     join_keys: array<string, JoinKey>
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
            // ── Procurement ──────────────────────────────────────────────────
            [
                'key' => 'suppliers',
                'label' => 'Fournisseurs',
                'description' => 'Fournisseurs Oracle Procurement (AP), avec leurs sites, contacts et adresses.',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
                'keywords' => ['fournisseur', 'fournisseurs', 'supplier', 'suppliers', 'vendeur', 'vendor', 'achat', 'procurement'],
                'fields' => ['SupplierId', 'Supplier', 'SupplierNumber', 'Status', 'SupplierType', 'BusinessRelationship', 'TaxOrganizationType', 'TaxRegistrationNumber', 'CreationDate', 'LastUpdateDate'],
                'preview_fields' => ['SupplierId', 'Supplier', 'SupplierNumber', 'Status'],
                'child_resources' => ['sites', 'contacts', 'addresses'],
                'child_fields' => [
                    'sites' => ['SiteId', 'SupplierSite', 'AddressLine1', 'AddressLine2', 'City', 'State', 'PostalCode', 'Country', 'Email', 'PhoneNumber', 'PrimaryPaySite', 'PurchasingEnabled'],
                    'contacts' => ['ContactId', 'FirstName', 'LastName', 'Email', 'PhoneNumber', 'AdministrativeContact', 'PrimaryContact'],
                    'addresses' => ['AddressId', 'AddressName', 'AddressLine1', 'City', 'State', 'PostalCode', 'Country', 'AddressPurpose'],
                ],
                'join_keys' => [
                    // La ressource invoices n'expose pas SupplierId : la clé
                    // commune vérifiée sur le tenant réel est SupplierNumber.
                    'invoices' => ['local_key' => 'SupplierNumber', 'remote_key' => 'SupplierNumber', 'label' => 'Factures de ce fournisseur'],
                    'purchase_orders' => ['local_key' => 'SupplierId', 'remote_key' => 'SupplierId', 'label' => 'Bons de commande de ce fournisseur'],
                ],
            ],
            [
                'key' => 'purchase_orders',
                'label' => 'Bons de commande',
                'description' => 'Bons de commande Oracle Procurement (PO), lignes, livraisons et distributions.',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrders',
                'keywords' => ['bon de commande', 'bons de commande', 'commande', 'commandes', 'purchase order', 'purchase orders', 'po', 'procurement'],
                'fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'SupplierId', 'SupplierSite', 'Status', 'Buyer', 'CurrencyCode', 'Total', 'OrderedAmountBeforeAdjustments', 'Description', 'OrderDate', 'CreationDate'],
                'preview_fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'Status'],
                'child_resources' => ['lines', 'schedules', 'distributions'],
                'child_fields' => [
                    'lines' => ['LineId', 'LineNumber', 'LineType', 'ItemDescription', 'CategoryName', 'Quantity', 'UnitPrice', 'LineAmount', 'LineStatus', 'NeedByDate'],
                    'schedules' => ['ScheduleId', 'ScheduleNumber', 'NeedByDate', 'PromisedDate', 'ShipToLocation', 'Quantity', 'ShipmentStatus', 'AccrualAmount'],
                    'distributions' => ['DistributionId', 'DistributionNumber', 'Quantity', 'ChargeAccount', 'ProjectNumber', 'TaskNumber', 'DistributionStatus'],
                ],
                'join_keys' => [
                    'suppliers' => ['local_key' => 'SupplierId', 'remote_key' => 'SupplierId', 'label' => 'Détail du fournisseur'],
                    'receipts' => ['local_key' => 'POHeaderId', 'remote_key' => 'POHeaderId', 'label' => 'Réceptions liées'],
                    // invoices ne porte pas POHeaderId : le lien réel est le numéro de commande.
                    'invoices' => ['local_key' => 'OrderNumber', 'remote_key' => 'PurchaseOrderNumber', 'label' => 'Factures issues de ce PO'],
                ],
            ],
            [
                'key' => 'purchase_agreements',
                'label' => 'Contrats d\'achat',
                'description' => 'Accords-cadres et contrats d\'achat Oracle Procurement (Blanket PO, CPA).',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/purchaseAgreements',
                'keywords' => ['contrat', 'contrats', 'accord', 'accords', 'accord-cadre', 'blanket', 'cpa', 'purchase agreement'],
                'fields' => ['AgreementHeaderId', 'AgreementNumber', 'Supplier', 'SupplierId', 'SupplierSite', 'Status', 'StartDate', 'EndDate', 'AgreementAmount', 'CurrencyCode', 'Description', 'CreationDate'],
                'preview_fields' => ['AgreementHeaderId', 'AgreementNumber', 'Supplier', 'Status'],
                'child_resources' => ['lines'],
                'child_fields' => [
                    'lines' => ['LineId', 'LineNumber', 'ItemDescription', 'CategoryName', 'AgreedQuantity', 'AgreedAmount', 'UnitPrice', 'LineStatus', 'ExpirationDate'],
                ],
                'join_keys' => [
                    'suppliers' => ['local_key' => 'SupplierId', 'remote_key' => 'SupplierId', 'label' => 'Détail du fournisseur'],
                ],
            ],
            [
                'key' => 'receipts',
                'label' => 'Réceptions',
                'description' => 'Réceptions de marchandises et services Oracle Procurement (Receiving).',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/receivingTransactions',
                'keywords' => ['reception', 'receptions', 'réception', 'réceptions', 'receipt', 'receipts', 'receiving', 'livraison', 'livraisons'],
                'fields' => ['TransactionId', 'ReceiptNumber', 'Supplier', 'SupplierId', 'TransactionType', 'TransactionDate', 'Quantity', 'UnitOfMeasure', 'ItemDescription'],
                'preview_fields' => ['TransactionId', 'ReceiptNumber', 'Supplier', 'TransactionDate'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [
                    'suppliers' => ['local_key' => 'SupplierId', 'remote_key' => 'SupplierId', 'label' => 'Détail du fournisseur'],
                    'purchase_orders' => ['local_key' => 'POHeaderId', 'remote_key' => 'POHeaderId', 'label' => 'Bon de commande source'],
                ],
            ],

            // ── Finance ──────────────────────────────────────────────────────
            [
                'key' => 'invoices',
                'label' => 'Factures fournisseurs (AP)',
                'description' => 'Factures fournisseurs Oracle Payables (AP), lignes et échéances de paiement.',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/invoices',
                'keywords' => ['facture', 'factures', 'invoice', 'invoices', 'payable', 'payables', 'ap', 'comptes fournisseurs'],
                'fields' => ['InvoiceId', 'InvoiceNumber', 'InvoiceAmount', 'AmountPaid', 'InvoiceDate', 'Supplier', 'SupplierNumber', 'SupplierSite', 'InvoiceCurrency', 'PaidStatus', 'ValidationStatus', 'ApprovalStatus', 'Description', 'BusinessUnit', 'PurchaseOrderNumber', 'CreationDate'],
                'preview_fields' => ['InvoiceId', 'InvoiceNumber', 'InvoiceAmount', 'InvoiceDate', 'Supplier'],
                'child_resources' => ['invoiceLines', 'invoiceInstallments'],
                'child_fields' => [
                    'invoiceLines' => ['LineNumber', 'LineType', 'LineAmount', 'Description', 'AccountCombination', 'ProjectNumber', 'TaskNumber', 'Quantity', 'UnitPrice'],
                    'invoiceInstallments' => ['InstallmentId', 'DueDate', 'GrossAmount', 'DiscountDate', 'DiscountAmount', 'PaymentMethod', 'PaymentStatus'],
                ],
                'join_keys' => [
                    'suppliers' => ['local_key' => 'SupplierNumber', 'remote_key' => 'SupplierNumber', 'label' => 'Détail du fournisseur'],
                ],
            ],
            [
                'key' => 'receivables_invoices',
                'label' => 'Factures clients (AR)',
                'description' => 'Factures clients Oracle Receivables (AR), avec lignes et transactions.',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/receivablesInvoices',
                'keywords' => ['facture client', 'factures clients', 'ar', 'receivable', 'receivables', 'comptes clients', 'client', 'clients'],
                'fields' => ['CustomerTransactionId', 'TransactionNumber', 'BillToCustomerName', 'BillToCustomerNumber', 'TransactionDate', 'DueDate', 'EnteredAmount', 'InvoiceBalanceAmount', 'InvoiceCurrencyCode', 'InvoiceStatus', 'BusinessUnit', 'TransactionType', 'PurchaseOrder'],
                'preview_fields' => ['CustomerTransactionId', 'TransactionNumber', 'BillToCustomerName', 'EnteredAmount', 'InvoiceStatus'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],
            [
                'key' => 'gl_journal_entries',
                'label' => 'Lots de journaux (GL)',
                'description' => 'Lots d\'écritures comptables Oracle General Ledger (Journal Batches) : statut, période et totaux comptabilisés.',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/journalBatches',
                'keywords' => ['journal', 'journaux', 'ecriture', 'ecritures', 'écriture', 'écritures', 'gl', 'general ledger', 'comptabilite', 'comptabilité', 'batch', 'lot'],
                'fields' => ['JeBatchId', 'BatchName', 'BatchDescription', 'Status', 'StatusMeaning', 'DefaultPeriodName', 'PostedDate', 'RunningTotalAccountedDr', 'RunningTotalAccountedCr', 'ApprovalStatusMeaning', 'CreatedBy', 'CreationDate'],
                'preview_fields' => ['JeBatchId', 'BatchName', 'DefaultPeriodName', 'Status'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],

            // ── HCM ──────────────────────────────────────────────────────────
            [
                'key' => 'workers',
                'label' => 'Employés',
                'description' => 'Employés Oracle HCM (personnes, affectations, contrats, contacts d\'urgence).',
                'domain' => 'HCM',
                'method' => 'GET',
                'path' => '/hcmRestApi/resources/11.13.18.05/workers',
                'keywords' => ['employe', 'employes', 'employé', 'employés', 'worker', 'workers', 'collaborateur', 'personnel', 'salarie', 'salarié', 'rh', 'hcm'],
                // La racine workers est volontairement mince côté Oracle : les noms,
                // emails et affectations sont dans les enfants (names, emails, assignments).
                'fields' => ['PersonId', 'PersonNumber', 'DateOfBirth', 'CountryOfBirth', 'CorrespondenceLanguage', 'ApplicantNumber', 'CreationDate', 'LastUpdateDate'],
                'preview_fields' => ['PersonId', 'PersonNumber', 'CreationDate'],
                'child_resources' => ['assignments', 'addresses', 'emails', 'phones', 'names'],
                'child_fields' => [
                    'assignments' => ['AssignmentId', 'AssignmentNumber', 'JobTitle', 'DepartmentName', 'LocationName', 'GradeCode', 'ManagerName', 'AssignmentStatus', 'EffectiveStartDate', 'EffectiveEndDate'],
                    'addresses' => ['AddressId', 'AddressType', 'AddressLine1', 'City', 'State', 'PostalCode', 'Country', 'PrimaryFlag'],
                    'emails' => ['EmailId', 'EmailType', 'EmailAddress', 'PrimaryFlag'],
                    'phones' => ['PhoneId', 'PhoneType', 'PhoneNumber', 'PrimaryFlag'],
                    'names' => ['PersonNameId', 'NameType', 'FirstName', 'LastName', 'MiddleName', 'Title'],
                ],
                'join_keys' => [
                    // workers.PersonNumber (majuscule) ↔ absences.personNumber (minuscule).
                    'absence_records' => ['local_key' => 'PersonNumber', 'remote_key' => 'personNumber', 'label' => 'Absences de cet employé'],
                ],
            ],
            [
                'key' => 'absence_records',
                'label' => 'Absences',
                'description' => 'Absences et congés Oracle HCM (Absence Management), par employé.',
                'domain' => 'HCM',
                'method' => 'GET',
                'path' => '/hcmRestApi/resources/11.13.18.05/absences',
                'keywords' => ['absence', 'absences', 'conge', 'congé', 'congés', 'conges', 'leave', 'leaves', 'rtt', 'maladie', 'arret', 'arrêt'],
                // Oracle Absences expose des attributs en camelCase minuscule.
                'fields' => ['personAbsenceEntryId', 'personNumber', 'absenceType', 'absenceReason', 'startDate', 'endDate', 'absenceDispStatus', 'duration', 'unitOfMeasure', 'employer', 'assignmentNumber'],
                'preview_fields' => ['personAbsenceEntryId', 'personNumber', 'absenceType', 'startDate', 'absenceDispStatus'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [
                    // absences.personNumber (minuscule) ↔ workers.PersonNumber (majuscule).
                    'workers' => ['local_key' => 'personNumber', 'remote_key' => 'PersonNumber', 'label' => 'Détail de l\'employé'],
                ],
            ],

            // ── Projets & Actifs ──────────────────────────────────────────────
            [
                'key' => 'projects',
                'label' => 'Projets (PPM)',
                'description' => 'Projets Oracle Project Portfolio Management (PPM), tâches et ressources affectées.',
                'domain' => 'Projets',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/projects',
                'keywords' => ['projet', 'projets', 'project', 'projects', 'ppm', 'portfolio', 'tache', 'tâche', 'wbs'],
                'fields' => ['ProjectId', 'ProjectNumber', 'ProjectName', 'ProjectStatus', 'ProjectTypeName', 'ProjectStartDate', 'ProjectEndDate', 'ProjectManagerName', 'ProjectDescription', 'BusinessUnitName', 'ProjectCurrencyCode', 'CreationDate'],
                'preview_fields' => ['ProjectId', 'ProjectNumber', 'ProjectName', 'ProjectStatus'],
                'child_resources' => ['tasks', 'projectResources'],
                'child_fields' => [
                    'tasks' => ['TaskId', 'TaskNumber', 'TaskName', 'Description', 'StartDate', 'FinishDate', 'Status', 'BillableIndicator', 'ChargableIndicator', 'BudgetedCost', 'BudgetedHours'],
                    'projectResources' => ['ResourceId', 'PersonNumber', 'DisplayName', 'ResourceRole', 'StartDate', 'EndDate', 'PlannedHours', 'ActualHours', 'BillingTitle'],
                ],
                // Ni invoices ni purchaseOrders n'exposent ProjectId en tête :
                // le lien projet se fait au niveau des lignes/distributions,
                // hors de portée d'une jointure d'en-têtes.
                'join_keys' => [],
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
            'child_fields' => $resource['child_fields'],
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
            $joinSummary = [];

            foreach ($resource['join_keys'] as $target => $def) {
                $joinSummary[] = "{$def['label']} (→ {$target} via {$def['local_key']})";
            }

            $lines[] = sprintf(
                "- %s (clé: %s, domaine: %s) — %s\n  champs: %s\n  enfants (expand): %s\n  jointures: %s",
                $resource['label'],
                $resource['key'],
                $resource['domain'],
                $resource['description'],
                implode(', ', $resource['fields']),
                $resource['child_resources'] === [] ? '(aucun)' : implode(', ', $resource['child_resources']),
                $joinSummary === [] ? '(aucune)' : implode(', ', $joinSummary),
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
