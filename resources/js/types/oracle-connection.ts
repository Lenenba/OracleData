export type OracleTenant = {
    id: number;
    key: string;
    type: 'fusion' | 'oic';
    label: string;
    base_url: string;
    username: string;
    source: 'database';
    auth_type: string;
    connection_count: number;
    verified_at: string | null;
    last_tested_at: string | null;
    is_default: boolean;
    is_active: boolean;
};

export type OicIntegration = {
    id: string;
    code: string | null;
    name: string;
    description: string | null;
    status: 'ACTIVATED' | 'CONFIGURED' | 'DEACTIVATED' | string;
    style: string | null;
    last_updated: string | null;
    completed_count: number;
    failed_count: number;
    aborted_count: number;
    processing_count: number;
};

export type OicInstance = {
    id: string | null;
    status: 'COMPLETED' | 'FAILED' | 'ABORTED' | 'PROCESSING' | string;
    started_at: string | null;
    finished_at: string | null;
    error: string | null;
    business_id: string | null;
};

export type OicError = {
    instance_id: string | null;
    integration_id: string | null;
    integration_name: string | null;
    error_message: string | null;
    started_at: string | null;
};

export type OicTenantShape = {
    id: number;
    key: string;
    label: string;
};

export type EditableOracleTenant = Pick<
    OracleTenant,
    | 'id'
    | 'key'
    | 'type'
    | 'label'
    | 'base_url'
    | 'username'
    | 'auth_type'
    | 'verified_at'
    | 'is_default'
    | 'is_active'
>;
