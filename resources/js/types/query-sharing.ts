export type QueryAccessLevel = 'private' | 'restricted' | 'organization';

export type QuerySharePermission = 'view' | 'execute' | 'clone' | 'manage';

export type QueryShareRecipientType = 'user' | 'group';

export type QueryShareStatus =
    | 'pending'
    | 'accepted'
    | 'active'
    | 'cancelled'
    | 'declined'
    | 'expired'
    | 'revoked';

export type QueryCapabilities = {
    update: boolean;
    execute: boolean;
    clone: boolean;
    manage_sharing: boolean;
};

export type QueryUserShareCandidate = {
    recipient_type: 'user';
    id: number;
    name: string;
    email: string;
};

export type QueryGroupShareCandidate = {
    recipient_type: 'group';
    id: number;
    name: string;
    description: string | null;
    member_count: number;
};

export type QueryShareCandidate =
    | QueryUserShareCandidate
    | QueryGroupShareCandidate;

export type QueryShareRecipientBase = {
    id: number;
    permission: QuerySharePermission;
    status: QueryShareStatus;
    expires_at: string | null;
    respond_by: string | null;
    accepted_at: string | null;
    declined_at: string | null;
    cancelled_at: string | null;
    revoked_at: string | null;
    is_active: boolean;
    can_update: boolean;
    can_revoke: boolean;
    can_cancel: boolean;
    shared_by: { id: number; name: string } | null;
};

export type QueryUserShareRecipient = QueryShareRecipientBase & {
    recipient_type: 'user';
    user: QueryUserShareCandidate;
};

export type QueryGroupShareRecipient = QueryShareRecipientBase & {
    recipient_type: 'group';
    group: QueryGroupShareCandidate;
};

export type QueryShareRecipient =
    | QueryUserShareRecipient
    | QueryGroupShareRecipient;

export type QuerySharingPaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type PaginatedQueryShareCandidates = {
    data: QueryShareCandidate[];
    meta: QuerySharingPaginationMeta;
};

export type PaginatedQueryShares = {
    data: QueryShareRecipient[];
    meta: QuerySharingPaginationMeta;
};
