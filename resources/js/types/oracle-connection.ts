export type OracleTenant = {
    id: number;
    key: string;
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

export type EditableOracleTenant = Pick<
    OracleTenant,
    | 'id'
    | 'key'
    | 'label'
    | 'base_url'
    | 'username'
    | 'auth_type'
    | 'verified_at'
    | 'is_default'
    | 'is_active'
>;
