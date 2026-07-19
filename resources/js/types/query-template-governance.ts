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

export type QueryTemplateGovernanceVersion = {
    id: number;
    version_number: number;
    status: QueryTemplateVersionStatus;
    definition: QueryTemplateVersionDefinition;
    translations: Partial<
        Record<'fr' | 'en' | 'es', QueryTemplateTranslationSnapshot>
    >;
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

export type QueryTemplateGovernanceDetail =
    QueryTemplateGovernanceSummary & {
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
