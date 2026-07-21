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

/**
 * Oracle HCM Workers collection — extracted and sanitized from the
 * "Workers" Postman collection (workers, assignments, managers, business
 * units, account combinations). Only GET requests targeting HCM/FSCM
 * REST API paths are included. Tenant-specific paths (containing literal
 * Oracle IDs) are preserved so the user can review them before importing.
 */
const WORKERS_COLLECTION = {
    info: {
        name: 'Oracle HCM Workers',
        schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    },
    item: [
        {
            name: 'Get workers (by PersonNumber with expand)',
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
            name: 'Get workers (list)',
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
            name: 'Get department by name',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/hcmRestApi/resources/11.13.18.05/hcmBusinessUnitsLOV',
                    host: ['{{URI}}'],
                    path: [
                        'hcmRestApi',
                        'resources',
                        '11.13.18.05',
                        'hcmBusinessUnitsLOV',
                    ],
                    query: [{ key: 'onlyData', value: 'true' }, { key: 'limit', value: '1' }],
                },
            },
        },
        {
            name: 'Get all purchase orders',
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
            name: 'Get account combinations',
            request: {
                method: 'GET',
                url: {
                    raw: '{{URI}}/fscmRestApi/resources/latest/glAccountCombinations',
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

/**
 * Catalog of built-in Oracle collections available in the import dialog.
 * Add new entries here to extend the catalog without touching the UI.
 */
export const ORACLE_CATALOG: CatalogEntry[] = [
    {
        id: 'oracle-hcm-workers',
        label: 'Oracle HCM Workers — Employee management',
        description:
            'Read queries for workers, assignments, managers, business units and account combinations.',
        itemCount: WORKERS_COLLECTION.item.length,
        collection: WORKERS_COLLECTION,
    },
];
