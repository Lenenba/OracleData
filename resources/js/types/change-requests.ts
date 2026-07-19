import type { QuerySharingPaginationMeta } from './query-sharing';

export type QueryChangeRequestStatus =
    | 'pending'
    | 'accepted'
    | 'rejected'
    | 'completed'
    | 'cancelled';

export type QueryChangeRequestUser = {
    id: number;
    name: string;
};

export type QueryChangeRequestCapabilities = {
    view?: boolean;
    comment?: boolean;
    update_status?: boolean;
    cancel?: boolean;
};

export type QueryChangeRequestSummary = {
    id: number;
    title: string;
    status: QueryChangeRequestStatus;
    requested_by: QueryChangeRequestUser | null;
    status_changed_by?: QueryChangeRequestUser | null;
    status_changed_at?: string | null;
    comment_count: number;
    created_at: string;
    updated_at: string;
    can: QueryChangeRequestCapabilities;
};

export type QueryChangeRequestComment = {
    id: number;
    body: string;
    author: QueryChangeRequestUser | null;
    mentions: QueryChangeRequestUser[];
    created_at: string;
};

export type QueryChangeRequestQuery = {
    id: number;
    name: string;
    is_archived?: boolean;
    is_owner: boolean;
    can_create_change_request: boolean;
};

export type PaginatedQueryChangeRequests = {
    data: QueryChangeRequestSummary[];
    meta: QuerySharingPaginationMeta;
};

export type PaginatedQueryChangeRequestComments = {
    data: QueryChangeRequestComment[];
    meta: QuerySharingPaginationMeta;
};
