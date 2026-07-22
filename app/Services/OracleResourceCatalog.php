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
 *     join_keys: array<string, JoinKey>,
 *     fallbacks?: list<array{path: string, params?: array<string, mixed>, strip_params?: list<string>}>,
 *     sql?: array<string, mixed>
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
                'sql' => [
                    'table' => 'POZ_SUPPLIERS_V',
                    'alias' => 'SUP',
                    'note' => 'Vue fournisseurs Oracle Procurement ; peut varier selon les droits BI Publisher.',
                    'columns' => [
                        'SupplierId' => 'VENDOR_ID',
                        'Supplier' => 'VENDOR_NAME',
                        'SupplierNumber' => 'SEGMENT1',
                        'Status' => 'ENABLED_FLAG',
                        'SupplierType' => 'VENDOR_TYPE_LOOKUP_CODE',
                        'TaxRegistrationNumber' => 'TAX_REGISTRATION_NUM',
                        'CreationDate' => 'CREATION_DATE',
                        'LastUpdateDate' => 'LAST_UPDATE_DATE',
                    ],
                ],
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
                'sql' => [
                    'table' => 'PO_HEADERS_ALL',
                    'alias' => 'PHA',
                    'note' => 'En-têtes PO ; lignes/schedules/distributions via PO_LINES_ALL, PO_LINE_LOCATIONS_ALL et PO_DISTRIBUTIONS_ALL.',
                    'columns' => [
                        'POHeaderId' => 'PO_HEADER_ID',
                        'OrderNumber' => 'SEGMENT1',
                        'Supplier' => '(SELECT SUP.VENDOR_NAME FROM POZ_SUPPLIERS_V SUP WHERE SUP.VENDOR_ID = PHA.VENDOR_ID)',
                        'SupplierId' => 'VENDOR_ID',
                        'SupplierSite' => 'VENDOR_SITE_ID',
                        'Status' => 'DOCUMENT_STATUS',
                        'StatusCode' => 'DOCUMENT_STATUS',
                        'Buyer' => 'AGENT_ID',
                        'BuyerDisplayName' => 'AGENT_ID',
                        'ProcurementBU' => 'PRC_BU_ID',
                        'RequisitioningBU' => 'REQ_BU_ID',
                        'CurrencyCode' => 'CURRENCY_CODE',
                        'Description' => 'COMMENTS',
                        'OrderDate' => 'CREATION_DATE',
                        'CreationDate' => 'CREATION_DATE',
                        'LastUpdateDate' => 'LAST_UPDATE_DATE',
                    ],
                    'child_tables' => [
                        'lines' => [
                            'table' => 'PO_LINES_ALL',
                            'alias' => 'PLA',
                            'join' => 'PLA.PO_HEADER_ID = PHA.PO_HEADER_ID',
                            'columns' => [
                                'LineId' => 'PO_LINE_ID',
                                'LineNumber' => 'LINE_NUM',
                                'LineType' => 'LINE_TYPE_ID',
                                'ItemDescription' => 'ITEM_DESCRIPTION',
                                'CategoryName' => 'CATEGORY_ID',
                                'Quantity' => 'QUANTITY',
                                'UnitPrice' => 'UNIT_PRICE',
                                'LineAmount' => 'AMOUNT',
                                'LineStatus' => 'LINE_STATUS',
                            ],
                        ],
                        'schedules' => [
                            'table' => 'PO_LINE_LOCATIONS_ALL',
                            'alias' => 'PLL',
                            'join' => 'PLL.PO_HEADER_ID = PHA.PO_HEADER_ID',
                            'columns' => [
                                'ScheduleId' => 'LINE_LOCATION_ID',
                                'ScheduleNumber' => 'SHIPMENT_NUM',
                                'NeedByDate' => 'NEED_BY_DATE',
                                'PromisedDate' => 'PROMISED_DATE',
                                'Quantity' => 'QUANTITY',
                                'ShipmentStatus' => 'SCHEDULE_STATUS',
                            ],
                        ],
                        'distributions' => [
                            'table' => 'PO_DISTRIBUTIONS_ALL',
                            'alias' => 'PDA',
                            'join' => 'PDA.PO_HEADER_ID = PHA.PO_HEADER_ID',
                            'columns' => [
                                'DistributionId' => 'PO_DISTRIBUTION_ID',
                                'DistributionNumber' => 'DISTRIBUTION_NUM',
                                'Quantity' => 'QUANTITY_ORDERED',
                                'ChargeAccount' => 'CODE_COMBINATION_ID',
                                'ProjectNumber' => 'PJC_PROJECT_ID',
                                'TaskNumber' => 'PJC_TASK_ID',
                            ],
                        ],
                    ],
                    'joins' => [
                        'suppliers' => [
                            'table' => 'POZ_SUPPLIERS_V',
                            'alias' => 'SUP',
                            'join' => 'SUP.VENDOR_ID = PHA.VENDOR_ID',
                            'columns' => [
                                'SupplierId' => 'VENDOR_ID',
                                'Supplier' => 'VENDOR_NAME',
                                'SupplierNumber' => 'SEGMENT1',
                            ],
                        ],
                        'invoices' => [
                            'table' => 'AP_INVOICES_ALL',
                            'alias' => 'AI',
                            'join' => 'AI.INVOICE_ID IN (SELECT AIL_PO.INVOICE_ID FROM AP_INVOICE_LINES_ALL AIL_PO WHERE AIL_PO.PO_HEADER_ID = PHA.PO_HEADER_ID)',
                            'columns' => [
                                'InvoiceId' => 'INVOICE_ID',
                                'InvoiceNumber' => 'INVOICE_NUM',
                                'InvoiceAmount' => 'INVOICE_AMOUNT',
                                'SupplierId' => 'VENDOR_ID',
                            ],
                        ],
                    ],
                ],
                'keywords' => ['bon de commande', 'bons de commande', 'commande', 'commandes', 'purchase order', 'purchase orders', 'po', 'procurement'],
                'fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'SupplierId', 'SupplierSite', 'Status', 'StatusCode', 'Buyer', 'BuyerDisplayName', 'ProcurementBU', 'RequisitioningBU', 'SoldToLegalEntity', 'BillToBU', 'CurrencyCode', 'Ordered', 'OrderedAmountBeforeAdjustments', 'Description', 'OrderDate', 'CreationDate', 'LastUpdateDate'],
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
                'fallbacks' => [
                    [
                        'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrders',
                        'params' => ['finder' => 'findByIntent;Intent=POUser'],
                        'strip_params' => ['q'],
                    ],
                    [
                        'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrders',
                        'params' => ['finder' => 'findByIntent;Intent=APUser'],
                        'strip_params' => ['q'],
                    ],
                    [
                        'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrdersLOV',
                        'strip_params' => ['fields', 'expand', 'orderBy'],
                    ],
                    [
                        'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrdersForReceiving',
                        'strip_params' => ['fields', 'expand', 'orderBy', 'q'],
                    ],
                ],
            ],
            [
                'key' => 'purchase_requisitions',
                'label' => 'Demandes d\'achat',
                'description' => 'Demandes d\'achat Oracle Procurement : demandeurs, BU, statut et montants.',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/purchaseRequisitions',
                'sql' => [
                    'table' => 'POR_REQUISITION_HEADERS_ALL',
                    'alias' => 'PRH',
                    'note' => 'En-têtes de demandes d’achat ; lignes via POR_REQUISITION_LINES_ALL.',
                    'columns' => [
                        'RequisitionHeaderId' => 'REQUISITION_HEADER_ID',
                        'Requisition' => 'REQUISITION_NUMBER',
                        'RequisitionNumber' => 'REQUISITION_NUMBER',
                        'Description' => 'DESCRIPTION',
                        'DocumentStatus' => 'DOCUMENT_STATUS',
                        'PreparerId' => 'PREPARER_ID',
                        'RequisitioningBU' => 'REQ_BU_ID',
                        'CreationDate' => 'CREATION_DATE',
                        'LastUpdateDate' => 'LAST_UPDATE_DATE',
                    ],
                    'child_tables' => [
                        'lines' => [
                            'table' => 'POR_REQUISITION_LINES_ALL',
                            'alias' => 'PRL',
                            'join' => 'PRL.REQUISITION_HEADER_ID = PRH.REQUISITION_HEADER_ID',
                            'columns' => [
                                'RequisitionLineId' => 'REQUISITION_LINE_ID',
                                'LineNumber' => 'LINE_NUMBER',
                                'ItemDescription' => 'ITEM_DESCRIPTION',
                                'CategoryName' => 'CATEGORY_ID',
                                'Quantity' => 'QUANTITY',
                                'UnitPrice' => 'UNIT_PRICE',
                                'LineAmount' => 'AMOUNT',
                                'NeedByDate' => 'NEED_BY_DATE',
                            ],
                        ],
                    ],
                ],
                'keywords' => ['demande achat', 'demande d achat', 'requisition', 'requisitions', 'da', 'purchase requisition', 'self service procurement'],
                'fields' => ['RequisitionHeaderId', 'Requisition', 'RequisitionNumber', 'Description', 'DocumentStatus', 'Preparer', 'PreparerId', 'Requester', 'RequisitioningBU', 'ProcurementBU', 'Total', 'CurrencyCode', 'CreationDate', 'LastUpdateDate'],
                'preview_fields' => ['RequisitionHeaderId', 'RequisitionNumber', 'Preparer', 'DocumentStatus'],
                'child_resources' => ['lines'],
                'child_fields' => [
                    'lines' => ['RequisitionLineId', 'LineNumber', 'ItemDescription', 'CategoryName', 'Quantity', 'UnitPrice', 'LineAmount', 'LineStatus', 'NeedByDate'],
                ],
                'join_keys' => [],
            ],
            [
                'key' => 'draft_purchase_orders',
                'label' => 'Bons de commande brouillons',
                'description' => 'Bons de commande en brouillon ou changement non finalisé Oracle Procurement.',
                'domain' => 'Procurement',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/draftPurchaseOrders',
                'keywords' => ['draft purchase order', 'brouillon commande', 'changement commande', 'commande brouillon'],
                'fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'SupplierId', 'SupplierSite', 'Status', 'Buyer', 'ProcurementBU', 'CurrencyCode', 'Description', 'CreationDate', 'LastUpdateDate'],
                'preview_fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'Status'],
                'child_resources' => ['lines'],
                'child_fields' => [
                    'lines' => ['LineId', 'LineNumber', 'LineType', 'ItemDescription', 'CategoryName', 'Quantity', 'UnitPrice', 'LineAmount', 'LineStatus'],
                ],
                'join_keys' => [
                    'suppliers' => ['local_key' => 'SupplierId', 'remote_key' => 'SupplierId', 'label' => 'Détail du fournisseur'],
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
                'sql' => [
                    'table' => 'RCV_TRANSACTIONS',
                    'alias' => 'RT',
                    'note' => 'Transactions de réception SCM.',
                    'columns' => [
                        'TransactionId' => 'TRANSACTION_ID',
                        'SupplierId' => 'VENDOR_ID',
                        'TransactionType' => 'TRANSACTION_TYPE',
                        'TransactionDate' => 'TRANSACTION_DATE',
                        'Quantity' => 'QUANTITY',
                        'UnitOfMeasure' => 'UNIT_OF_MEASURE',
                        'POHeaderId' => 'PO_HEADER_ID',
                    ],
                    'joins' => [
                        'purchase_orders' => [
                            'table' => 'PO_HEADERS_ALL',
                            'alias' => 'PHA',
                            'join' => 'PHA.PO_HEADER_ID = RT.PO_HEADER_ID',
                            'columns' => [
                                'POHeaderId' => 'PO_HEADER_ID',
                                'OrderNumber' => 'SEGMENT1',
                                'Status' => 'DOCUMENT_STATUS',
                            ],
                        ],
                        'suppliers' => [
                            'table' => 'POZ_SUPPLIERS_V',
                            'alias' => 'SUP',
                            'join' => 'SUP.VENDOR_ID = RT.VENDOR_ID',
                            'columns' => [
                                'SupplierId' => 'VENDOR_ID',
                                'Supplier' => 'VENDOR_NAME',
                                'SupplierNumber' => 'SEGMENT1',
                            ],
                        ],
                    ],
                ],
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
            [
                'key' => 'purchase_orders_for_receiving',
                'label' => 'Bons de commande à réceptionner',
                'description' => 'Bons de commande disponibles pour réception dans Inventory/Receiving.',
                'domain' => 'Inventory',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/purchaseOrdersForReceiving',
                'keywords' => ['receptionner', 'réceptionner', 'purchase orders for receiving', 'po receiving', 'receiving'],
                'fields' => ['POHeaderId', 'PurchaseOrder', 'Supplier', 'SupplierId', 'SoldToLegalEntity', 'OrganizationCode', 'OrganizationId', 'Buyer', 'DocumentStatus', 'CreationDate'],
                'preview_fields' => ['POHeaderId', 'PurchaseOrder', 'Supplier', 'DocumentStatus'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [
                    'purchase_orders' => ['local_key' => 'POHeaderId', 'remote_key' => 'POHeaderId', 'label' => 'Bon de commande'],
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
                'sql' => [
                    'table' => 'AP_INVOICES_ALL',
                    'alias' => 'AI',
                    'note' => 'Factures fournisseurs Payables ; lignes via AP_INVOICE_LINES_ALL, échéances via AP_PAYMENT_SCHEDULES_ALL.',
                    'columns' => [
                        'InvoiceId' => 'INVOICE_ID',
                        'InvoiceNumber' => 'INVOICE_NUM',
                        'InvoiceAmount' => 'INVOICE_AMOUNT',
                        'AmountPaid' => 'AMOUNT_PAID',
                        'InvoiceDate' => 'INVOICE_DATE',
                        'Supplier' => '(SELECT SUP.VENDOR_NAME FROM POZ_SUPPLIERS_V SUP WHERE SUP.VENDOR_ID = AI.VENDOR_ID)',
                        'SupplierNumber' => '(SELECT SUP.SEGMENT1 FROM POZ_SUPPLIERS_V SUP WHERE SUP.VENDOR_ID = AI.VENDOR_ID)',
                        'SupplierSite' => 'VENDOR_SITE_ID',
                        'InvoiceCurrency' => 'INVOICE_CURRENCY_CODE',
                        'PaidStatus' => 'PAYMENT_STATUS_FLAG',
                        'ApprovalStatus' => 'APPROVAL_STATUS',
                        'Description' => 'DESCRIPTION',
                        'BusinessUnit' => 'ORG_ID',
                        'PurchaseOrderNumber' => '(SELECT MAX(PHA.SEGMENT1) FROM AP_INVOICE_LINES_ALL AIL_PO JOIN PO_HEADERS_ALL PHA ON PHA.PO_HEADER_ID = AIL_PO.PO_HEADER_ID WHERE AIL_PO.INVOICE_ID = AI.INVOICE_ID)',
                        'CreationDate' => 'CREATION_DATE',
                    ],
                    'child_tables' => [
                        'invoiceLines' => [
                            'table' => 'AP_INVOICE_LINES_ALL',
                            'alias' => 'AIL',
                            'join' => 'AIL.INVOICE_ID = AI.INVOICE_ID',
                            'columns' => [
                                'LineNumber' => 'LINE_NUMBER',
                                'LineType' => 'LINE_TYPE_LOOKUP_CODE',
                                'LineAmount' => 'AMOUNT',
                                'Description' => 'DESCRIPTION',
                                'AccountCombination' => 'DIST_CODE_COMBINATION_ID',
                                'ProjectNumber' => 'PJC_PROJECT_ID',
                                'TaskNumber' => 'PJC_TASK_ID',
                                'Quantity' => 'QUANTITY_INVOICED',
                                'UnitPrice' => 'UNIT_PRICE',
                            ],
                        ],
                        'invoiceInstallments' => [
                            'table' => 'AP_PAYMENT_SCHEDULES_ALL',
                            'alias' => 'APS',
                            'join' => 'APS.INVOICE_ID = AI.INVOICE_ID',
                            'columns' => [
                                'InstallmentId' => 'PAYMENT_NUM',
                                'DueDate' => 'DUE_DATE',
                                'GrossAmount' => 'GROSS_AMOUNT',
                                'DiscountDate' => 'DISCOUNT_DATE',
                                'DiscountAmount' => 'DISCOUNT_AMOUNT_AVAILABLE',
                                'PaymentMethod' => 'PAYMENT_METHOD_CODE',
                                'PaymentStatus' => 'PAYMENT_STATUS_FLAG',
                            ],
                        ],
                    ],
                    'joins' => [
                        'suppliers' => [
                            'table' => 'POZ_SUPPLIERS_V',
                            'alias' => 'SUP',
                            'join' => 'SUP.VENDOR_ID = AI.VENDOR_ID',
                            'columns' => [
                                'SupplierId' => 'VENDOR_ID',
                                'Supplier' => 'VENDOR_NAME',
                                'SupplierNumber' => 'SEGMENT1',
                            ],
                        ],
                    ],
                ],
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
                'key' => 'customer_accounts',
                'label' => 'Comptes clients',
                'description' => 'Comptes clients Receivables : client, compte, site principal et informations fiscales.',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/customerAccountSitesLOV',
                'keywords' => ['client', 'clients', 'customer', 'customers', 'receivables', 'ar', 'compte client', 'customer account'],
                'fields' => ['CustomerAccountId', 'AccountNumber', 'CustomerName', 'PartyNumber', 'SiteUseId', 'SiteName', 'SitePurpose', 'PrimarySite', 'SetName', 'TaxRegistrationNumber', 'TaxpayerIdentificationNumber'],
                'preview_fields' => ['CustomerAccountId', 'AccountNumber', 'CustomerName', 'PrimarySite'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],
            [
                'key' => 'external_bank_accounts',
                'label' => 'Comptes bancaires externes',
                'description' => 'Comptes bancaires externes liés aux fournisseurs, clients ou personnes.',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/externalBankAccounts',
                'keywords' => ['banque', 'bancaire', 'bank', 'bank account', 'iban', 'supplier bank', 'customer bank'],
                'fields' => ['BankAccountId', 'BankAccountNumber', 'MaskedBankAccountNumber', 'IBAN', 'BankName', 'BankBranchName', 'CountryCode', 'CurrencyCode', 'AccountOwnerPartyName', 'StartDate', 'EndDate'],
                'preview_fields' => ['BankAccountId', 'MaskedBankAccountNumber', 'BankName', 'AccountOwnerPartyName'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],
            [
                'key' => 'ledgers',
                'label' => 'Ledgers',
                'description' => 'Ledgers Oracle Financials disponibles pour les écritures et rapports comptables.',
                'domain' => 'Finance',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/ledgersLOV',
                'keywords' => ['ledger', 'ledgers', 'livre', 'grand livre', 'comptabilite', 'comptabilité', 'gl'],
                'fields' => ['LedgerId', 'Name', 'ShortName', 'Description', 'LedgerCategoryCode', 'CurrencyCode', 'ChartOfAccountsId', 'PeriodSetName', 'AccountingCalendarId'],
                'preview_fields' => ['LedgerId', 'Name', 'CurrencyCode', 'LedgerCategoryCode'],
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
                'sql' => [
                    'table' => 'GL_JE_BATCHES',
                    'alias' => 'GJB',
                    'note' => 'Lots de journaux General Ledger.',
                    'columns' => [
                        'JeBatchId' => 'JE_BATCH_ID',
                        'BatchName' => 'NAME',
                        'BatchDescription' => 'DESCRIPTION',
                        'Status' => 'STATUS',
                        'DefaultPeriodName' => 'DEFAULT_PERIOD_NAME',
                        'PostedDate' => 'POSTED_DATE',
                        'RunningTotalAccountedDr' => 'RUNNING_TOTAL_ACCOUNTED_DR',
                        'RunningTotalAccountedCr' => 'RUNNING_TOTAL_ACCOUNTED_CR',
                        'ApprovalStatusMeaning' => 'APPROVAL_STATUS_CODE',
                        'CreatedBy' => 'CREATED_BY',
                        'CreationDate' => 'CREATION_DATE',
                    ],
                ],
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
                'sql' => [
                    'table' => 'PER_ALL_PEOPLE_F',
                    'alias' => 'PAPF',
                    'note' => 'Personnes HCM ; les noms/emails/affectations nécessitent des tables HCM liées.',
                    'columns' => [
                        'PersonId' => 'PERSON_ID',
                        'PersonNumber' => 'PERSON_NUMBER',
                        'DateOfBirth' => 'DATE_OF_BIRTH',
                        'CountryOfBirth' => 'COUNTRY_OF_BIRTH',
                        'CorrespondenceLanguage' => 'CORRESPONDENCE_LANGUAGE',
                        'CreationDate' => 'CREATION_DATE',
                        'LastUpdateDate' => 'LAST_UPDATE_DATE',
                    ],
                ],
                'keywords' => ['employe', 'employes', 'employé', 'employés', 'worker', 'workers', 'collaborateur', 'personnel', 'salarie', 'salarié', 'rh', 'hcm'],
                // La racine workers est volontairement mince côté Oracle : les noms,
                // emails et affectations sont dans les enfants (names, emails, assignments).
                'fields' => ['PersonId', 'PersonNumber', 'DateOfBirth', 'CountryOfBirth', 'CorrespondenceLanguage', 'ApplicantNumber', 'CreationDate', 'LastUpdateDate'],
                'preview_fields' => ['PersonId', 'PersonNumber', 'CreationDate'],
                'child_resources' => ['assignments', 'addresses', 'emails', 'phones', 'names', 'workRelationships'],
                'child_fields' => [
                    'assignments' => ['AssignmentId', 'AssignmentNumber', 'JobTitle', 'DepartmentName', 'LocationName', 'GradeCode', 'ManagerName', 'AssignmentStatus', 'EffectiveStartDate', 'EffectiveEndDate'],
                    'addresses' => ['AddressId', 'AddressType', 'AddressLine1', 'City', 'State', 'PostalCode', 'Country', 'PrimaryFlag'],
                    'emails' => ['EmailId', 'EmailType', 'EmailAddress', 'PrimaryFlag'],
                    'phones' => ['PhoneId', 'PhoneType', 'PhoneNumber', 'PrimaryFlag'],
                    'names' => ['PersonNameId', 'NameType', 'FirstName', 'LastName', 'MiddleName', 'Title'],
                    // workRelationships : contrats de travail, affectations imbriquées et managers.
                    // Les chemins imbriqués Oracle (workRelationships.assignments.managers) sont
                    // gérés via le paramètre expand brut ; cette liste couvre le premier niveau.
                    'workRelationships' => ['WorkRelationshipId', 'LegalEntityName', 'WorkerType', 'PrimaryWorkerFlag', 'WorkRelationshipStartDate', 'WorkRelationshipTerminatedDate', 'CurrentWorker'],
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
            [
                'key' => 'inventory_organizations',
                'label' => 'Organisations inventaire',
                'description' => 'Organisations Inventory/SCM utilisables pour stocks, réceptions et mouvements.',
                'domain' => 'Inventory',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/inventoryOrganizationsOpenLOV',
                'keywords' => ['inventory organization', 'organisation inventaire', 'stock', 'warehouse', 'entrepot', 'entrepôt'],
                'fields' => ['OrganizationId', 'OrganizationCode', 'OrganizationName', 'BusinessUnitId', 'BusinessUnitName', 'LegalEntityId', 'LegalEntityName'],
                'preview_fields' => ['OrganizationId', 'OrganizationCode', 'OrganizationName'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],
            [
                'key' => 'item_categories',
                'label' => 'Catégories articles',
                'description' => 'Catégories articles SCM et Procurement.',
                'domain' => 'Inventory',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/allItemCategoriesLOV',
                'keywords' => ['article', 'articles', 'item', 'items', 'categorie', 'catégorie', 'category'],
                'fields' => ['CategoryId', 'CategoryName', 'CategoryCode', 'CatalogId', 'CatalogCode', 'CatalogName', 'Description', 'EnabledFlag'],
                'preview_fields' => ['CategoryId', 'CategoryName', 'CategoryCode'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],
            [
                'key' => 'subinventory_items',
                'label' => 'Sous-inventaires',
                'description' => 'Sous-inventaires et emplacements utilisés par Inventory Management.',
                'domain' => 'Inventory',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/subinventoryItems',
                'keywords' => ['subinventory', 'sous inventaire', 'stock', 'emplacement', 'locator'],
                'fields' => ['SubinventoryCode', 'SubinventoryDescription', 'OrganizationId', 'OrganizationCode', 'InventoryItemId', 'ItemNumber', 'ItemDescription', 'QuantityOnHand'],
                'preview_fields' => ['SubinventoryCode', 'OrganizationCode', 'ItemNumber', 'QuantityOnHand'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],
            [
                'key' => 'receipt_costs',
                'label' => 'Coûts de réception',
                'description' => 'Coûts associés aux réceptions SCM/Cost Management.',
                'domain' => 'Inventory',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/receiptCosts',
                'keywords' => ['cout reception', 'coût réception', 'receipt cost', 'cost management', 'inventory cost'],
                'fields' => ['ReceiptCostId', 'ReceiptNumber', 'ItemNumber', 'InventoryItemId', 'OrganizationCode', 'Quantity', 'UnitCost', 'CurrencyCode', 'TransactionDate'],
                'preview_fields' => ['ReceiptCostId', 'ReceiptNumber', 'ItemNumber', 'UnitCost'],
                'child_resources' => [],
                'child_fields' => [],
                'join_keys' => [],
            ],

            // ── Projets & Actifs ──────────────────────────────────────────────
            [
                'key' => 'projects',
                'label' => 'Projets (PPM)',
                'description' => 'Projets Oracle Project Portfolio Management (PPM), tâches et ressources affectées.',
                'domain' => 'Projets',
                'method' => 'GET',
                'path' => '/fscmRestApi/resources/11.13.18.05/projects',
                'sql' => [
                    'table' => 'PJF_PROJECTS_ALL_VL',
                    'alias' => 'PPA',
                    'note' => 'Projets PPM ; les tâches sont généralement exposées par les vues PJF/PJT selon le modèle BI.',
                    'columns' => [
                        'ProjectId' => 'PROJECT_ID',
                        'ProjectNumber' => 'SEGMENT1',
                        'ProjectName' => 'NAME',
                        'ProjectStatus' => 'PROJECT_STATUS_CODE',
                        'ProjectTypeName' => 'PROJECT_TYPE_ID',
                        'ProjectStartDate' => 'START_DATE',
                        'ProjectEndDate' => 'COMPLETION_DATE',
                        'ProjectDescription' => 'DESCRIPTION',
                        'BusinessUnitName' => 'ORG_ID',
                        'ProjectCurrencyCode' => 'PROJECT_CURRENCY_CODE',
                        'CreationDate' => 'CREATION_DATE',
                    ],
                ],
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
            'sql' => $resource['sql'] ?? null,
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
