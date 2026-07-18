<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QueryTemplateSeeder extends Seeder
{
    /**
     * Seed immutable, globally available query templates without depending on
     * a demo user or on any user's Oracle credentials.
     */
    public function run(): void
    {
        $now = now();
        $categories = Category::query()->pluck('id', 'slug');
        $templates = [
            [
                'slug' => 'supplier-invoices-above-amount',
                'name' => 'Factures fournisseurs supérieures à un montant',
                'description' => 'Affiche les factures et leurs fournisseurs lorsque le montant dépasse le seuil choisi.',
                'category_id' => $categories->get('finance'),
                'resource_key' => 'invoices',
                'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
                'parameters' => [
                    'resource_key' => 'invoices',
                    'fields' => 'Supplier,SupplierNumber,InvoiceNumber,InvoiceAmount,InvoiceCurrency,InvoiceDate',
                    'orderBy' => 'InvoiceAmount:desc',
                    'limit' => 50,
                ],
                'parameter_definitions' => [
                    [
                        'key' => 'minimum_amount',
                        'label' => 'Montant minimum',
                        'description' => 'Seules les factures strictement supérieures à ce montant seront retournées.',
                        'type' => 'number',
                        'required' => true,
                        'default' => 1000,
                        'min' => 0,
                        'max' => 999999999,
                        'step' => 100,
                        'binding' => [
                            'kind' => 'filter',
                            'field' => 'InvoiceAmount',
                            'operator' => '>',
                        ],
                    ],
                    $this->resultLimitDefinition(50),
                ],
                'is_active' => true,
                'sort_order' => 10,
                'translations' => $this->withResultLimitTranslations([
                    'fr' => [
                        'name' => 'Factures fournisseurs supérieures à un montant',
                        'description' => 'Affiche les factures et leurs fournisseurs lorsque le montant dépasse le seuil choisi.',
                        'parameter_labels' => [
                            'minimum_amount' => 'Montant minimum',
                        ],
                        'parameter_descriptions' => [
                            'minimum_amount' => 'Seules les factures strictement supérieures à ce montant seront retournées.',
                        ],
                    ],
                    'en' => [
                        'name' => 'Supplier invoices above an amount',
                        'description' => 'Shows supplier invoices whose amount exceeds the selected threshold.',
                        'parameter_labels' => [
                            'minimum_amount' => 'Minimum amount',
                        ],
                        'parameter_descriptions' => [
                            'minimum_amount' => 'Only invoices strictly above this amount are returned.',
                        ],
                    ],
                    'es' => [
                        'name' => 'Facturas de proveedores superiores a un importe',
                        'description' => 'Muestra las facturas de proveedores cuyo importe supera el umbral seleccionado.',
                        'parameter_labels' => [
                            'minimum_amount' => 'Importe mínimo',
                        ],
                        'parameter_descriptions' => [
                            'minimum_amount' => 'Solo se devolverán las facturas estrictamente superiores a este importe.',
                        ],
                    ],
                ]),
            ],
            [
                'slug' => 'unpaid-supplier-invoices-before-date',
                'name' => 'Factures fournisseurs non payées avant une date',
                'description' => 'Repère les factures encore impayées dont la date de facture est égale ou antérieure à la date choisie.',
                'category_id' => $categories->get('finance'),
                'resource_key' => 'invoices',
                'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
                'parameters' => [
                    'resource_key' => 'invoices',
                    'fields' => 'InvoiceNumber,Supplier,SupplierNumber,InvoiceAmount,AmountPaid,InvoiceCurrency,InvoiceDate,PaidStatus',
                    'q' => "PaidStatus!='Y'",
                    'orderBy' => 'InvoiceDate:asc',
                    'limit' => 50,
                ],
                'parameter_definitions' => [
                    [
                        'key' => 'invoice_before',
                        'label' => 'Factures émises à cette date ou avant',
                        'description' => 'Date maximale incluse de la facture, au format année-mois-jour.',
                        'type' => 'date',
                        'required' => true,
                        'binding' => [
                            'kind' => 'filter',
                            'field' => 'InvoiceDate',
                            'operator' => '<=',
                        ],
                    ],
                    $this->resultLimitDefinition(50),
                ],
                'is_active' => true,
                'sort_order' => 20,
                'translations' => $this->withResultLimitTranslations([
                    'fr' => [
                        'name' => 'Factures fournisseurs non payées avant une date',
                        'description' => 'Repère les factures encore impayées dont la date de facture est égale ou antérieure à la date choisie.',
                        'parameter_labels' => [
                            'invoice_before' => 'Factures émises à cette date ou avant',
                        ],
                        'parameter_descriptions' => [
                            'invoice_before' => 'Date maximale incluse de la facture, au format année-mois-jour.',
                        ],
                    ],
                    'en' => [
                        'name' => 'Unpaid supplier invoices on or before a date',
                        'description' => 'Finds unpaid supplier invoices whose invoice date is on or before the selected date.',
                        'parameter_labels' => [
                            'invoice_before' => 'Invoices issued on or before',
                        ],
                        'parameter_descriptions' => [
                            'invoice_before' => 'Latest included invoice date, in year-month-day format.',
                        ],
                    ],
                    'es' => [
                        'name' => 'Facturas de proveedores impagadas hasta una fecha',
                        'description' => 'Localiza las facturas de proveedores impagadas cuya fecha es igual o anterior a la fecha seleccionada.',
                        'parameter_labels' => [
                            'invoice_before' => 'Facturas emitidas hasta esta fecha',
                        ],
                        'parameter_descriptions' => [
                            'invoice_before' => 'Fecha máxima incluida de la factura, en formato año-mes-día.',
                        ],
                    ],
                ]),
            ],
            [
                'slug' => 'customer-invoices-above-balance',
                'name' => 'Factures clients avec un solde supérieur à un montant',
                'description' => 'Liste les factures clients dont le solde restant dépasse le seuil choisi.',
                'category_id' => $categories->get('finance'),
                'resource_key' => 'receivables_invoices',
                'resource_path' => '/fscmRestApi/resources/11.13.18.05/receivablesInvoices',
                'parameters' => [
                    'resource_key' => 'receivables_invoices',
                    'fields' => 'TransactionNumber,BillToCustomerName,BillToCustomerNumber,TransactionDate,DueDate,EnteredAmount,InvoiceBalanceAmount,InvoiceCurrencyCode,InvoiceStatus',
                    'orderBy' => 'InvoiceBalanceAmount:desc',
                    'limit' => 50,
                ],
                'parameter_definitions' => [
                    [
                        'key' => 'minimum_amount',
                        'label' => 'Solde minimum',
                        'description' => 'Seules les factures dont le solde restant est strictement supérieur à ce montant seront retournées.',
                        'type' => 'number',
                        'required' => true,
                        'default' => 5000,
                        'min' => 0,
                        'max' => 999999999,
                        'step' => 500,
                        'binding' => [
                            'kind' => 'filter',
                            'field' => 'InvoiceBalanceAmount',
                            'operator' => '>',
                        ],
                    ],
                    $this->resultLimitDefinition(50),
                ],
                'is_active' => true,
                'sort_order' => 30,
                'translations' => $this->withResultLimitTranslations([
                    'fr' => [
                        'name' => 'Factures clients avec un solde supérieur à un montant',
                        'description' => 'Liste les factures clients dont le solde restant dépasse le seuil choisi.',
                        'parameter_labels' => [
                            'minimum_amount' => 'Solde minimum',
                        ],
                        'parameter_descriptions' => [
                            'minimum_amount' => 'Seules les factures dont le solde restant est strictement supérieur à ce montant seront retournées.',
                        ],
                    ],
                    'en' => [
                        'name' => 'Customer invoices with a balance above an amount',
                        'description' => 'Lists customer invoices whose remaining balance exceeds the selected threshold.',
                        'parameter_labels' => [
                            'minimum_amount' => 'Minimum balance',
                        ],
                        'parameter_descriptions' => [
                            'minimum_amount' => 'Only invoices whose remaining balance is strictly above this amount are returned.',
                        ],
                    ],
                    'es' => [
                        'name' => 'Facturas de clientes con saldo superior a un importe',
                        'description' => 'Muestra las facturas de clientes cuyo saldo pendiente supera el umbral seleccionado.',
                        'parameter_labels' => [
                            'minimum_amount' => 'Saldo mínimo',
                        ],
                        'parameter_descriptions' => [
                            'minimum_amount' => 'Solo se devolverán las facturas cuyo saldo pendiente sea estrictamente superior a este importe.',
                        ],
                    ],
                ]),
            ],
            [
                'slug' => 'suppliers-by-status',
                'name' => 'Fournisseurs par statut',
                'description' => 'Affiche les fournisseurs correspondant au statut sélectionné.',
                'category_id' => $categories->get('fournisseurs'),
                'resource_key' => 'suppliers',
                'resource_path' => '/fscmRestApi/resources/11.13.18.05/suppliers',
                'parameters' => [
                    'resource_key' => 'suppliers',
                    'fields' => 'Supplier,SupplierNumber,Status,SupplierType,BusinessRelationship,CreationDate',
                    'orderBy' => 'Supplier:asc',
                    'limit' => 50,
                ],
                'parameter_definitions' => [
                    [
                        'key' => 'supplier_status',
                        'label' => 'Statut du fournisseur',
                        'description' => 'Seuls les fournisseurs correspondant à ce statut seront retournés.',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'ACTIVE',
                        'options' => [
                            ['value' => 'ACTIVE', 'label' => 'Actif'],
                            ['value' => 'INACTIVE', 'label' => 'Inactif'],
                        ],
                        'binding' => [
                            'kind' => 'filter',
                            'field' => 'Status',
                            'operator' => '=',
                        ],
                    ],
                    $this->resultLimitDefinition(50),
                ],
                'is_active' => true,
                'sort_order' => 40,
                'translations' => $this->withResultLimitTranslations([
                    'fr' => [
                        'name' => 'Fournisseurs par statut',
                        'description' => 'Affiche les fournisseurs correspondant au statut sélectionné.',
                        'parameter_labels' => [
                            'supplier_status' => 'Statut du fournisseur',
                        ],
                        'parameter_descriptions' => [
                            'supplier_status' => 'Seuls les fournisseurs correspondant à ce statut seront retournés.',
                        ],
                        'parameter_options' => [
                            'supplier_status' => [
                                'ACTIVE' => 'Actif',
                                'INACTIVE' => 'Inactif',
                            ],
                        ],
                    ],
                    'en' => [
                        'name' => 'Suppliers by status',
                        'description' => 'Shows suppliers matching the selected status.',
                        'parameter_labels' => [
                            'supplier_status' => 'Supplier status',
                        ],
                        'parameter_descriptions' => [
                            'supplier_status' => 'Only suppliers matching this status are returned.',
                        ],
                        'parameter_options' => [
                            'supplier_status' => [
                                'ACTIVE' => 'Active',
                                'INACTIVE' => 'Inactive',
                            ],
                        ],
                    ],
                    'es' => [
                        'name' => 'Proveedores por estado',
                        'description' => 'Muestra los proveedores que corresponden al estado seleccionado.',
                        'parameter_labels' => [
                            'supplier_status' => 'Estado del proveedor',
                        ],
                        'parameter_descriptions' => [
                            'supplier_status' => 'Solo se devolverán los proveedores que correspondan a este estado.',
                        ],
                        'parameter_options' => [
                            'supplier_status' => [
                                'ACTIVE' => 'Activo',
                                'INACTIVE' => 'Inactivo',
                            ],
                        ],
                    ],
                ]),
            ],
        ];

        $rows = array_map(function (array $template) use ($now): array {
            unset($template['translations']);

            return [
                ...$template,
                'parameters' => json_encode($template['parameters'], JSON_THROW_ON_ERROR),
                'parameter_definitions' => json_encode($template['parameter_definitions'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $templates);

        DB::table('query_templates')->upsert(
            $rows,
            ['slug'],
            [
                'name',
                'description',
                'category_id',
                'resource_key',
                'resource_path',
                'parameters',
                'parameter_definitions',
                'is_active',
                'sort_order',
                'updated_at',
            ],
        );

        $templateIds = DB::table('query_templates')
            ->whereIn('slug', array_column($templates, 'slug'))
            ->pluck('id', 'slug');
        $translationRows = [];

        foreach ($templates as $template) {
            $templateId = $templateIds->get($template['slug']);

            foreach ($template['translations'] as $locale => $translation) {
                $translationRows[] = [
                    'query_template_id' => $templateId,
                    'locale' => $locale,
                    'name' => $translation['name'],
                    'description' => $translation['description'] ?? null,
                    'parameter_labels' => $this->jsonOrNull($translation['parameter_labels'] ?? []),
                    'parameter_descriptions' => $this->jsonOrNull($translation['parameter_descriptions'] ?? []),
                    'parameter_options' => $this->jsonOrNull($translation['parameter_options'] ?? []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('query_template_translations')->upsert(
            $translationRows,
            ['query_template_id', 'locale'],
            [
                'name',
                'description',
                'parameter_labels',
                'parameter_descriptions',
                'parameter_options',
                'updated_at',
            ],
        );
    }

    /**
     * Add the shared result-limit copy to every supported locale.
     *
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    private function withResultLimitTranslations(array $translations): array
    {
        $limitTranslations = [
            'fr' => [
                'label' => 'Nombre maximal de résultats',
                'description' => 'L’aperçu reste limité à 25 lignes ; l’exécution utilise cette valeur.',
            ],
            'en' => [
                'label' => 'Maximum number of results',
                'description' => 'The preview remains limited to 25 rows; the full run uses this value.',
            ],
            'es' => [
                'label' => 'Número máximo de resultados',
                'description' => 'La vista previa se limita a 25 filas; la ejecución completa utiliza este valor.',
            ],
        ];

        foreach ($limitTranslations as $locale => $content) {
            $translations[$locale]['parameter_labels'] = [
                ...($translations[$locale]['parameter_labels'] ?? []),
                'result_limit' => $content['label'],
            ];
            $translations[$locale]['parameter_descriptions'] = [
                ...($translations[$locale]['parameter_descriptions'] ?? []),
                'result_limit' => $content['description'],
            ];
        }

        return $translations;
    }

    /** @param array<string, mixed> $values */
    private function jsonOrNull(array $values): ?string
    {
        return $values === [] ? null : json_encode($values, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function resultLimitDefinition(int $default): array
    {
        return [
            'key' => 'result_limit',
            'label' => 'Nombre maximal de résultats',
            'description' => 'L’aperçu reste limité à 25 lignes ; l’exécution utilise cette valeur.',
            'type' => 'integer',
            'required' => true,
            'default' => $default,
            'min' => 1,
            'max' => 500,
            'binding' => [
                'kind' => 'parameter',
                'key' => 'limit',
            ],
        ];
    }
}
