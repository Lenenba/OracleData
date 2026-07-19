import type {
    QuerySharingPaginationMeta,
    QueryUserShareCandidate,
} from './query-sharing';

export type GroupRole = 'owner' | 'manager' | 'member';

export type GroupSummary = {
    id: number;
    name: string;
    description: string | null;
    member_count: number;
    current_user_role: GroupRole;
    can_update: boolean;
    can_delete: boolean;
    can_manage_members: boolean;
};

export type GroupMember = {
    id: number;
    name: string;
    email: string;
    role: GroupRole;
    can_update_role: boolean;
    can_remove: boolean;
};

export type PaginatedGroupMembers = {
    data: GroupMember[];
    meta: QuerySharingPaginationMeta;
};

export type PaginatedGroupMemberCandidates = {
    data: Pick<QueryUserShareCandidate, 'id' | 'name' | 'email'>[];
    meta: QuerySharingPaginationMeta;
};
