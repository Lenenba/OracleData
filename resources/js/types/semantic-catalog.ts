export type SemanticLocale = 'fr' | 'en' | 'es';

export type SemanticClassification =
    | 'unclassified'
    | 'public'
    | 'internal'
    | 'confidential'
    | 'restricted';

export type SemanticDataCategory =
    | 'general'
    | 'personal'
    | 'financial'
    | 'hr'
    | 'credential'
    | 'operational';

export type SemanticSqlMappingStatus =
    | 'unmapped'
    | 'exact'
    | 'derived'
    | 'unsupported';

export type SemanticRelationKind = 'expand' | 'join' | 'reference';

export type SemanticRelationStatus = 'draft' | 'published' | 'deprecated';

export type SemanticCardinality =
    | 'one_to_one'
    | 'one_to_many'
    | 'many_to_one'
    | 'many_to_many';

export type SemanticCatalogUser = {
    id: number;
    name: string;
    email?: string;
};

export type SemanticResourceTranslation = {
    locale: SemanticLocale;
    name: string;
    description: string | null;
    synonyms: string[];
    examples: string[];
};

export type SemanticFieldTranslation = {
    locale: SemanticLocale;
    name: string;
    description: string | null;
    synonyms: string[];
    examples: string[];
};

export type SemanticRelationTranslation = {
    locale: SemanticLocale;
    name: string;
    description: string | null;
};

export type SemanticGlossaryTranslation = {
    locale: SemanticLocale;
    term: string;
    definition: string | null;
    synonyms: string[];
    forbidden_terms: string[];
    examples: string[];
};

export type SemanticCatalogCapabilities = {
    view: boolean;
    update_resource: boolean;
    update_fields: boolean;
    manage_relations: boolean;
    manage_glossary: boolean;
};

export type SemanticCatalogResourceSummary = {
    id: number;
    resource_key: string;
    source_name: string;
    domain: string;
    api_path: string;
    business_owner: SemanticCatalogUser | null;
    technical_owner: SemanticCatalogUser | null;
    classification: SemanticClassification;
    data_category: SemanticDataCategory;
    sql_table: string | null;
    sql_alias: string | null;
    sql_mapping_status: SemanticSqlMappingStatus;
    mapping_notes: string | null;
    is_active: boolean;
    lock_version: number;
    translations: SemanticResourceTranslation[];
    fields_count: number;
    relations_count: number;
    mapped_fields_count: number;
};

export type SemanticCatalogField = {
    id: number;
    child_key: string;
    source_name: string;
    data_type: string | null;
    classification: SemanticClassification;
    data_category: SemanticDataCategory;
    sql_expression: string | null;
    sql_mapping_status: SemanticSqlMappingStatus;
    is_nullable: boolean;
    is_updatable: boolean;
    is_active: boolean;
    last_seen_at: string | null;
    lock_version: number;
    translations: SemanticFieldTranslation[];
};

export type SemanticCatalogRelation = {
    id: number;
    relation_key: string;
    kind: SemanticRelationKind;
    target_key: string;
    source_field: string;
    target_field: string;
    cardinality: SemanticCardinality;
    sql_table: string | null;
    sql_alias: string | null;
    sql_join: string | null;
    status: SemanticRelationStatus;
    is_active: boolean;
    lock_version: number;
    translations: SemanticRelationTranslation[];
};

export type SemanticCatalogResource = SemanticCatalogResourceSummary & {
    fields: SemanticCatalogField[];
    relations: SemanticCatalogRelation[];
};

export type SemanticGlossaryTerm = {
    id: number;
    term_key: string;
    domain: string;
    classification: SemanticClassification;
    data_category: SemanticDataCategory;
    is_active: boolean;
    lock_version: number;
    translations: SemanticGlossaryTranslation[];
};

export type SemanticCatalogSummary = {
    resources: number;
    fields: number;
    relations: number;
    glossary_terms: number;
    mapped_fields: number;
};

export type SemanticCatalogIndexProps = {
    resources: SemanticCatalogResourceSummary[];
    glossary: SemanticGlossaryTerm[];
    summary: SemanticCatalogSummary;
    filters: {
        search: string;
        domain: string | null;
        classification: SemanticClassification | null;
    };
    domains: string[];
    ownerCandidates: SemanticCatalogUser[];
    capabilities: SemanticCatalogCapabilities;
};

export type SemanticCatalogShowProps = {
    resource: SemanticCatalogResource;
    ownerCandidates: SemanticCatalogUser[];
    targetResources: Array<{
        id: number;
        resource_key: string;
        name: string;
        fields: string[];
        local_key: string;
        remote_key: string;
    }>;
    childResources: Array<{
        key: string;
        name: string;
    }>;
    capabilities: SemanticCatalogCapabilities;
};

export type OracleSchemaAttribute = {
    name: string;
    type?: string | null;
    mandatory?: boolean;
    nullable?: boolean;
    updatable?: boolean;
    max_length?: number | null;
    precision?: number | null;
    scale?: number | null;
    [key: string]: unknown;
};

export type OracleSchemaSnapshot = {
    id: number;
    schema_hash: string;
    synced_at: string;
    source: string;
    diff: {
        added: string[];
        removed: string[];
        changed: string[];
    };
};

export type OracleSchemaImpactSummary = {
    templates_count: number;
    queries_count: number;
    executions_count: number;
    acknowledged_at: string | null;
};

export type OracleSchemaDriftStatus =
    | 'never_synced'
    | 'current'
    | 'changed'
    | 'acknowledged';

export type OracleTenantSchemaResource = {
    resource_key: string;
    label: string;
    domain: string;
    api_path: string;
    source: string | null;
    title: string | null;
    fields: string[];
    attributes: OracleSchemaAttribute[];
    schema_hash: string | null;
    discovered_at: string | null;
    drift_status: OracleSchemaDriftStatus;
    drift: {
        added: string[];
        removed: string[];
        changed: string[];
        detected_at: string | null;
    };
    impacts: OracleSchemaImpactSummary;
    history: OracleSchemaSnapshot[];
};

export type OracleSchemaPageProps = {
    tenant: {
        id: number;
        key: string;
        label: string;
        is_active: boolean;
        verified_at: string | null;
    };
    resources: OracleTenantSchemaResource[];
    summary: {
        resources: number;
        synchronized: number;
        drifted: number;
        impacted: number;
    };
    capabilities: {
        synchronize: boolean;
        acknowledge_impacts: boolean;
    };
};
