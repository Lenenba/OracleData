export type User = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    email_verified_at: string | null;
    onboarding_completed_at: string | null;
    is_super_admin: boolean;
    locale: 'fr' | 'en' | 'es';
    timezone: string;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    can_view_query_template_governance: boolean;
    onboarding: {
        completed: boolean;
        required: boolean;
    };
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
