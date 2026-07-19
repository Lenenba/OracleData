export type QueryTemplateGovernanceStatus =
    | 'draft'
    | 'review'
    | 'published'
    | 'archived';

export type QueryTemplateVersionStatus =
    | 'draft'
    | 'review'
    | 'published'
    | 'superseded';

export type GovernanceUser = {
    id: number;
    name: string;
    email?: string;
};

export type QueryTemplateGovernanceRole =
    | 'template_editor'
    | 'template_publisher';

export type GovernanceRoleCandidate = GovernanceUser & {
    email: string;
    roles: QueryTemplateGovernanceRole[];
};

export type QueryTemplateGovernanceCapabilities = {
    view: boolean;
    create_draft: boolean;
    update_draft: boolean;
    update_technical_definition: boolean;
    submit: boolean;
    publish: boolean;
    restore: boolean;
    certify: boolean;
    revoke_certification: boolean;
    archive: boolean;
    manage_roles: boolean;
    assign_technical_owner: boolean;
    run_quality_validation: boolean;
    capture_quality_reference: boolean;
};

export type OracleResourceSuggestion = {
    key: string;
    label: string;
    description: string;
    domain: string;
    method: string;
    path: string;
    keywords: string[];
    preview_fields: string[];
    fields: string[];
    child_resources: string[];
    child_fields: Record<string, string[]>;
    join_keys: Record<
        string,
        { local_key: string; remote_key: string; label: string }
    >;
    sql: Record<string, unknown> | null;
};

export type GovernanceVersionReference = {
    id: number;
    version_number: number;
};

export type GovernanceOpenVersion = GovernanceVersionReference & {
    status: 'draft' | 'review';
    lock_version: number;
};

export type QueryTemplateGovernanceSummary = {
    slug: string;
    name: string;
    governance_status: QueryTemplateGovernanceStatus;
    is_active: boolean;
    sort_order: number;
    lock_version: number;
    published_at: string | null;
    published_version: GovernanceVersionReference | null;
    open_version: GovernanceOpenVersion | null;
    business_owner: GovernanceUser | null;
    technical_owner: GovernanceUser | null;
    review_due_at: string | null;
    is_review_overdue: boolean;
    certification: QueryTemplateCertification | null;
};

export type QueryTemplateTranslationSnapshot = {
    name?: string;
    description?: string | null;
    parameter_labels?: Record<string, string>;
    parameter_descriptions?: Record<string, string>;
    parameter_options?: Record<string, unknown>;
};

export type QueryTemplateVersionDefinition = {
    name?: string;
    description?: string | null;
    category_id?: number | null;
    resource_key?: string;
    resource_path?: string;
    parameters?: Record<string, unknown>;
    parameter_definitions?: Array<Record<string, unknown>>;
    sort_order?: number;
    [key: string]: unknown;
};

export type QueryTemplateQualityRule = Record<string, unknown>;

export type QueryTemplateQualityHealthStatus =
    | 'unknown'
    | 'healthy'
    | 'degraded'
    | 'failing';

export type QueryTemplateQualityRunStatus = 'passed' | 'failed' | 'error';

export type QueryTemplateQualityAssertionResult = {
    index: number;
    type: string;
    required: boolean;
    passed: boolean;
    code: string;
    metrics: Record<string, unknown>;
};

export type QueryTemplateQualityRun = {
    id: number;
    version_number: number;
    purpose: string;
    status: QueryTemplateQualityRunStatus;
    score: number | null;
    tenant_key: string | null;
    duration_ms: number;
    rows_count: number;
    assertions_passed: number;
    assertions_failed: number;
    error_code: string | null;
    reference_dataset_id: number | null;
    assertion_results: QueryTemplateQualityAssertionResult[];
    started_at: string;
    finished_at: string;
};

export type QueryTemplateReferenceScenario =
    | 'baseline'
    | 'filter'
    | 'join'
    | 'duplicates'
    | 'order'
    | 'aggregate'
    | 'nulls';

export type QueryTemplateReferenceDataset = {
    id: number;
    version_number: number;
    name: string;
    scenario: QueryTemplateReferenceScenario;
    tenant_key: string | null;
    rows_count: number;
    dataset_hash: string;
    captured_by: GovernanceUser | null;
    captured_at: string;
};

export type QueryTemplateQualityHealth = {
    status: QueryTemplateQualityHealthStatus;
    score: number | null;
    failure_streak: number;
    is_slow: boolean;
    is_broken: boolean;
    is_stale: boolean;
    certification_suspended: boolean;
    last_run_at: string | null;
};

export type QueryTemplateQualityOverview = {
    health: QueryTemplateQualityHealth;
    latest_run: QueryTemplateQualityRun | null;
    runs: QueryTemplateQualityRun[];
    references: QueryTemplateReferenceDataset[];
};

export type QueryTemplateGovernanceVersion = {
    id: number;
    version_number: number;
    status: QueryTemplateVersionStatus;
    definition: QueryTemplateVersionDefinition;
    translations: Partial<
        Record<'fr' | 'en' | 'es', QueryTemplateTranslationSnapshot>
    >;
    quality_rules: QueryTemplateQualityRule[];
    change_summary: string | null;
    content_hash: string;
    lock_version: number;
    created_by: GovernanceUser | null;
    submitted_by: GovernanceUser | null;
    published_by: GovernanceUser | null;
    submitted_at: string | null;
    published_at: string | null;
    created_at: string | null;
    restored_from_version_id: number | null;
};

export type QueryTemplateGovernanceVersionOption = {
    id: number;
    version_number: number;
    status: QueryTemplateVersionStatus;
    lock_version: number;
    created_at: string | null;
    published_at: string | null;
    restored_from_version_id: number | null;
};

export type QueryTemplateCertification = {
    id: number;
    query_template_version_id: number;
    version_number: number;
    public_note: string | null;
    certified_by: GovernanceUser | null;
    certified_at: string;
    lock_version: number;
    is_effective: boolean;
};

export type QueryTemplateVersionComparisonChange = {
    path: string;
    section: string;
    kind: 'added' | 'removed' | 'changed';
    before: unknown;
    after: unknown;
};

export type QueryTemplateVersionComparison = {
    from_version: QueryTemplateGovernanceVersion;
    to_version: QueryTemplateGovernanceVersion;
    summary: {
        added: number;
        removed: number;
        changed: number;
        total: number;
    };
    changes: QueryTemplateVersionComparisonChange[];
};

export type QueryTemplateGovernanceDetail = QueryTemplateGovernanceSummary & {
    description: string | null;
    resource_key: string;
    resource_path: string;
    category_id: number | null;
    parameters: Record<string, unknown>;
    parameter_definitions: Array<Record<string, unknown>>;
    translations: Partial<
        Record<'fr' | 'en' | 'es', QueryTemplateTranslationSnapshot>
    >;
};

export type GovernanceCategory = {
    id: number;
    slug: string;
};

export type LaravelPaginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};
