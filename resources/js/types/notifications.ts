import type {
    QuerySharePermission,
    QueryShareStatus,
    QuerySharingPaginationMeta,
} from './query-sharing';
import type {
    QueryChangeRequestStatus,
    QueryChangeRequestUser,
} from './change-requests';

export type NotificationFilter = 'all' | 'unread';

export type NotificationSummary = {
    unread_count: number;
};

export type AppNotificationQuery = {
    id: number;
    name: string;
    is_archived: boolean;
};

export type AppNotificationShare = {
    id: number;
    status: QueryShareStatus;
    permission: QuerySharePermission;
    expires_at: string | null;
    respond_by: string | null;
    accepted_at: string | null;
    declined_at: string | null;
    cancelled_at: string | null;
    revoked_at: string | null;
    is_pending: boolean;
    is_expired: boolean;
    can_accept: boolean;
    can_decline: boolean;
    query: AppNotificationQuery | null;
    invited_by: {
        id: number;
        name: string;
    } | null;
};

export type AppNotificationChangeRequest = {
    id: number;
    title: string;
    status: QueryChangeRequestStatus;
    can_view: boolean;
};

export type AppNotificationKind =
    | 'query_share_invitation'
    | 'query_share_invitation_accepted'
    | 'query_share_invitation_declined'
    | 'query_change_request_created'
    | 'query_change_request_commented'
    | 'query_change_request_mentioned'
    | 'query_change_request_status_changed'
    | 'unknown';

type AppNotificationBase = {
    id: string;
    type: string;
    kind: AppNotificationKind;
    read_at: string | null;
    created_at: string;
    actor: QueryChangeRequestUser | null;
    query: AppNotificationQuery | null;
    is_available: boolean;
};

type QueryShareNotification = AppNotificationBase & {
    kind:
        | 'query_share_invitation'
        | 'query_share_invitation_accepted'
        | 'query_share_invitation_declined';
    share: AppNotificationShare | null;
    change_request: null;
};

type QueryChangeRequestNotification = AppNotificationBase & {
    kind:
        | 'query_change_request_created'
        | 'query_change_request_commented'
        | 'query_change_request_mentioned'
        | 'query_change_request_status_changed';
    share: null;
    change_request: AppNotificationChangeRequest | null;
};

type UnknownNotification = AppNotificationBase & {
    kind: 'unknown';
    share: null;
    change_request: null;
};

export type AppNotification =
    | QueryShareNotification
    | QueryChangeRequestNotification
    | UnknownNotification;

export type PaginatedNotifications = {
    data: AppNotification[];
    meta: QuerySharingPaginationMeta;
};
