/**
 * Lot 12A+ — Built-in Postman collection catalog for Oracle HCM / FSCM.
 *
 * Each entry embeds a minimal Postman v2.1 collection object with only
 * the importable GET items — credentials, hosts, scripts and examples are
 * never included.
 *
 * The `collection` object is the value that PostmanImportDialog normally
 * receives from a parsed JSON file upload; its structure must be compatible
 * with PostmanCollectionImporter::parse().
 *
 * To add a new catalog entry:
 *   1. Define a const collection object following the Postman v2.1 schema.
 *   2. Include only GET requests targeting /hcmRestApi/ or /fscmRestApi/ paths.
 *   3. Never include credentials, hosts, body, scripts or examples.
 *   4. Push an entry into ORACLE_CATALOG at the bottom of this file.
 *   5. Add translation keys queries.importCatalog<Id> / queries.importCatalog<Id>Desc
 *      in resources/js/locales/{fr,en,es}.json.
 */

export type CatalogEntry = {
    id: string;
    label: string;
    description: string;
    itemCount: number;
    collection: {
        info: { name: string; schema: string };
        item: readonly unknown[];
    };
};

// ─── Oracle HCM Workers ───────────────────────────────────────────────────────

/**
 * Comprehensive Oracle HCM Workers collection.
 * Covers the full employee lifecycle: identity, contact info, employment,
 * assignments, positions, salary, managers, direct reports and work schedules.
 *
 * Extracted and sanitised from the standard Oracle HCM REST API catalogue
 * (version 11.13.18.05). Only GET requests are included; tenant-specific
 * paths (containing literal IDs) are preserved so the user can review them
 * before importing.
 */
const WORKERS_COLLECTION = {
    info: {
        name: 'Oracle HCM Workers',
        schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    },
    item: [
        // ── Identity & directory ──────────────────────────────────────────────
        {
            name: 'List workers',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [],
                },
            },
        },
        {
            name: 'Get worker by PersonNumber',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [{ key: 'q', value: 'PersonNumber=25773' }],
                },
            },
        },
        {
            name: 'Get worker with full profile (names, addresses, emails, phones)',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?q=PersonNumber=25773&expand=names,addresses,emails,phones',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'q', value: 'PersonNumber=25773' },
                        {
                            key: 'expand',
                            value: 'names,addresses,emails,phones',
                        },
                    ],
                },
            },
        },
        {
            name: 'Get worker with assignments and managers',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?q=PersonNumber=25773&expand=workRelationships.assignments.managers',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'q', value: 'PersonNumber=25773' },
                        {
                            key: 'expand',
                            value: 'workRelationships.assignments.managers',
                        },
                    ],
                },
            },
        },
        {
            name: 'Get worker complete profile (all sub-resources)',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?q=PersonNumber=25773&expand=names,addresses,emails,phones,workRelationships.assignments.managers',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'q', value: 'PersonNumber=25773' },
                        {
                            key: 'expand',
                            value: 'names,addresses,emails,phones,workRelationships.assignments.managers',
                        },
                    ],
                },
            },
        },
        {
            name: 'Search workers by last name',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?q=names.LastName=Smith&expand=names',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'q', value: 'names.LastName=Smith' },
                        { key: 'expand', value: 'names' },
                    ],
                },
            },
        },
        {
            name: 'List workers with names (paginated)',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=names&limit=100&offset=0',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'expand', value: 'names' },
                        { key: 'limit', value: '100' },
                        { key: 'offset', value: '0' },
                    ],
                },
            },
        },
        {
            name: 'Get worker names (sub-resource)',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?fields=PersonId,PersonNumber&expand=names&q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'fields', value: 'PersonId,PersonNumber' },
                        { key: 'expand', value: 'names' },
                        { key: 'q', value: 'PersonNumber=25773' },
                    ],
                },
            },
        },
        // ── Assignments & positions ───────────────────────────────────────────
        {
            name: 'Get worker assignments',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=workRelationships.assignments&q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        {
                            key: 'expand',
                            value: 'workRelationships.assignments',
                        },
                        { key: 'q', value: 'PersonNumber=25773' },
                    ],
                },
            },
        },
        {
            name: 'Get worker work relationships',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=workRelationships&q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'expand', value: 'workRelationships' },
                        { key: 'q', value: 'PersonNumber=25773' },
                    ],
                },
            },
        },
        {
            name: 'List workers with assignment fields',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=workRelationships.assignments&fields=PersonId,PersonNumber,workRelationships.assignments.AssignmentId,workRelationships.assignments.AssignmentNumber,workRelationships.assignments.PositionCode,workRelationships.assignments.DepartmentName&limit=50',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        {
                            key: 'expand',
                            value: 'workRelationships.assignments',
                        },
                        {
                            key: 'fields',
                            value: 'PersonId,PersonNumber,workRelationships.assignments.AssignmentId,workRelationships.assignments.AssignmentNumber,workRelationships.assignments.PositionCode,workRelationships.assignments.DepartmentName',
                        },
                        { key: 'limit', value: '50' },
                    ],
                },
            },
        },
        // ── Managers & org chart ──────────────────────────────────────────────
        {
            name: 'Get worker managers (line manager chain)',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=workRelationships.assignments.managers&q=PersonNumber=25773&fields=PersonId,PersonNumber,workRelationships.assignments.managers.ManagerPersonNumber,workRelationships.assignments.managers.ManagerType',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        {
                            key: 'expand',
                            value: 'workRelationships.assignments.managers',
                        },
                        { key: 'q', value: 'PersonNumber=25773' },
                        {
                            key: 'fields',
                            value: 'PersonId,PersonNumber,workRelationships.assignments.managers.ManagerPersonNumber,workRelationships.assignments.managers.ManagerType',
                        },
                    ],
                },
            },
        },
        // ── Contact information ───────────────────────────────────────────────
        {
            name: 'Get worker phones',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=phones&q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'expand', value: 'phones' },
                        { key: 'q', value: 'PersonNumber=25773' },
                    ],
                },
            },
        },
        {
            name: 'Get worker emails',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=emails&q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'expand', value: 'emails' },
                        { key: 'q', value: 'PersonNumber=25773' },
                    ],
                },
            },
        },
        {
            name: 'Get worker addresses',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?expand=addresses&q=PersonNumber=25773',
                    host: ['{{URI}}'],
                    path: ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    query: [
                        { key: 'expand', value: 'addresses' },
                        { key: 'q', value: 'PersonNumber=25773' },
                    ],
                },
            },
        },
        // ── Business units / departments ──────────────────────────────────────
        {
            name: 'List HCM business units (LOV)',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/hcmBusinessUnitsLOV?onlyData=true',
                    host: ['{{URI}}'],
                    path: [
                        'hcmRestApi',
                        'resources',
                        '11.13.18.05',
                        'hcmBusinessUnitsLOV',
                    ],
                    query: [{ key: 'onlyData', value: 'true' }],
                },
            },
        },
        {
            name: 'Get business unit by name',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/hcmBusinessUnitsLOV?q=BusinessUnitName=Vision Corporation&onlyData=true',
                    host: ['{{URI}}'],
                    path: [
                        'hcmRestApi',
                        'resources',
                        '11.13.18.05',
                        'hcmBusinessUnitsLOV',
                    ],
                    query: [
                        {
                            key: 'q',
                            value: 'BusinessUnitName=Vision Corporation',
                        },
                        { key: 'onlyData', value: 'true' },
                    ],
                },
            },
        },
    ],
} as const;

// ─── Oracle FSCM Finance ──────────────────────────────────────────────────────

/**
 * Oracle FSCM Finance collection.
 * Covers Accounts Payable (invoices, payments), Procurement (purchase orders,
 * suppliers), General Ledger (account combinations, journal entries) and
 * basic LOV resources useful for filtering.
 */
const FSCM_FINANCE_COLLECTION = {
    info: {
        name: 'Oracle FSCM Finance',
        schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    },
    item: [
        // ── Procurement ───────────────────────────────────────────────────────
        {
            name: 'List purchase orders',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/purchaseOrders',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'purchaseOrders',
                    ],
                    query: [],
                },
            },
        },
        {
            name: 'Get purchase order by number',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/purchaseOrders?q=OrderNumber=US-0000001',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'purchaseOrders',
                    ],
                    query: [{ key: 'q', value: 'OrderNumber=US-0000001' }],
                },
            },
        },
        {
            name: 'List purchase orders with lines',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/purchaseOrders?expand=lines&limit=25',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'purchaseOrders',
                    ],
                    query: [
                        { key: 'expand', value: 'lines' },
                        { key: 'limit', value: '25' },
                    ],
                },
            },
        },
        {
            name: 'List suppliers',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/suppliers',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'suppliers',
                    ],
                    query: [],
                },
            },
        },
        {
            name: 'Search supplier by name',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/suppliers?q=Supplier=Acme Corp',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'suppliers',
                    ],
                    query: [{ key: 'q', value: 'Supplier=Acme Corp' }],
                },
            },
        },
        {
            name: 'List suppliers with sites',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/suppliers?expand=sites&limit=50',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'suppliers',
                    ],
                    query: [
                        { key: 'expand', value: 'sites' },
                        { key: 'limit', value: '50' },
                    ],
                },
            },
        },
        // ── Accounts Payable ──────────────────────────────────────────────────
        {
            name: 'List AP invoices',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/invoices',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'invoices',
                    ],
                    query: [],
                },
            },
        },
        {
            name: 'Get invoice by supplier',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/invoices?q=SupplierName=Acme Corp&limit=50',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'invoices',
                    ],
                    query: [
                        { key: 'q', value: 'SupplierName=Acme Corp' },
                        { key: 'limit', value: '50' },
                    ],
                },
            },
        },
        {
            name: 'List invoices with lines',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/11.13.18.05/invoices?expand=invoiceLines&limit=25',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        '11.13.18.05',
                        'invoices',
                    ],
                    query: [
                        { key: 'expand', value: 'invoiceLines' },
                        { key: 'limit', value: '25' },
                    ],
                },
            },
        },
        // ── General Ledger ────────────────────────────────────────────────────
        {
            name: 'Get account combinations',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/latest/glAccountCombinations?onlyData=true',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        'latest',
                        'glAccountCombinations',
                    ],
                    query: [{ key: 'onlyData', value: 'true' }],
                },
            },
        },
        {
            name: 'Search account combination by segment',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/latest/glAccountCombinations?q=AccountCombination=10-2331-31010-2190003-000000-00-0000&onlyData=true&limit=1',
                    host: ['{{URI}}'],
                    path: [
                        'fscmRestApi',
                        'resources',
                        'latest',
                        'glAccountCombinations',
                    ],
                    query: [
                        {
                            key: 'q',
                            value: 'AccountCombination=10-2331-31010-2190003-000000-00-0000',
                        },
                        { key: 'onlyData', value: 'true' },
                        { key: 'limit', value: '1' },
                    ],
                },
            },
        },
    ],
} as const;

// ─── Catalog registry ─────────────────────────────────────────────────────────

/**
 * Catalog of built-in Oracle collections available in the import dialog.
 * Add new entries here to extend the catalog without touching the UI.
 *
 * Each entry is rendered as a radio option in the "Catalog" tab of the
 * PostmanImportDialog. The `collection` field is passed verbatim to the
 * preview endpoint — it must be a valid Postman v2.1 structure.
 */
export const ORACLE_CATALOG: CatalogEntry[] = [
    {
        id: 'oracle-hcm-workers',
        label: 'Oracle HCM Workers — Employee management',
        description:
            'Read queries for workers, assignments, managers, contact info (phones, emails, addresses) and business units.',
        itemCount: WORKERS_COLLECTION.item.length,
        collection: WORKERS_COLLECTION,
    },
    {
        id: 'oracle-fscm-finance',
        label: 'Oracle FSCM Finance — AP, Procurement & GL',
        description:
            'Read queries for purchase orders, suppliers, AP invoices and GL account combinations.',
        itemCount: FSCM_FINANCE_COLLECTION.item.length,
        collection: FSCM_FINANCE_COLLECTION,
    },
];
