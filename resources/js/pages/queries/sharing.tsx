import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    History,
    Search,
    Send,
    Share2,
    ShieldCheck,
    Trash2,
    UserPlus,
    UserRound,
    Users,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { QueryAccessLevelBadge } from '@/components/queries/query-access-level-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import groupRoutes from '@/routes/groups';
import queries from '@/routes/queries';
import type {
    PaginatedQueryShareCandidates,
    PaginatedQueryShares,
    QueryAccessLevel,
    QuerySharePermission,
    QueryShareRecipient,
    QueryShareRecipientType,
    QueryUserShareRecipient,
} from '@/types';

type SharingQuery = {
    id: number;
    name: string;
    access_level: QueryAccessLevel;
    owner: { id: number; name: string };
    can_change_access_level: boolean;
    delegation_expires_at: string | null;
};

type SharingProps = {
    query: SharingQuery;
    permissions: QuerySharePermission[];
    groupPermissions: QuerySharePermission[];
    accessLevels: QueryAccessLevel[];
    recipientType: QueryShareRecipientType;
    historyRecipientType: QueryShareRecipientType;
    search: string;
    candidates: PaginatedQueryShareCandidates;
    pendingInvitations: QueryShareRecipient[];
    activeShares: QueryShareRecipient[];
    shareHistory: PaginatedQueryShares;
};

type Feedback = { type: 'success' | 'error'; message: string } | null;

const sharingUrl = (queryId: number) => queries.shares.index.url(queryId);
const storeShareUrl = (
    queryId: number,
    recipientType: QueryShareRecipientType,
) =>
    recipientType === 'group'
        ? queries.groupShares.store.url(queryId)
        : queries.invitations.store.url(queryId);
const updateShareUrl = (
    queryId: number,
    recipientType: QueryShareRecipientType,
    shareId: number,
) =>
    recipientType === 'group'
        ? queries.groupShares.update.url([queryId, shareId])
        : queries.shares.update.url([queryId, shareId]);
const destroyShareUrl = (
    queryId: number,
    recipientType: QueryShareRecipientType,
    shareId: number,
) =>
    recipientType === 'group'
        ? queries.groupShares.destroy.url([queryId, shareId])
        : queries.shares.destroy.url([queryId, shareId]);
const accessLevelUrl = (queryId: number) => queries.accessLevel.url(queryId);

function recipientName(share: QueryShareRecipient): string {
    return share.recipient_type === 'user' ? share.user.name : share.group.name;
}

async function errorMessage(response: Response): Promise<string | null> {
    const payload = (await response.json().catch(() => null)) as {
        message?: unknown;
        errors?: Record<string, unknown>;
    } | null;

    if (typeof payload?.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    const firstError = Object.values(payload?.errors ?? {})
        .flatMap((value) => (Array.isArray(value) ? value : [value]))
        .find((value): value is string => typeof value === 'string');

    return firstError ?? null;
}

async function mutate(
    url: string,
    method: 'POST' | 'PATCH' | 'DELETE',
    data?: Record<string, unknown>,
): Promise<void> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCsrfToken(),
        },
        credentials: 'same-origin',
        body: data === undefined ? undefined : JSON.stringify(data),
    });

    if (!response.ok) {
        throw new Error((await errorMessage(response)) ?? 'Request failed.');
    }
}

function toDateTimeLocal(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value.slice(0, 16);
    }

    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
}

function toUtcIso(value: string): string | null {
    if (value === '') {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toISOString();
}

function ShareRow({
    queryId,
    share,
    permissions,
    maximumExpiration,
    onChanged,
    onFeedback,
}: {
    queryId: number;
    share: QueryShareRecipient;
    permissions: QuerySharePermission[];
    maximumExpiration: string | null;
    onChanged: () => void;
    onFeedback: (feedback: Feedback) => void;
}) {
    const { t, formatDate } = useI18n();
    const [permission, setPermission] = useState(share.permission);
    const [expiresAt, setExpiresAt] = useState(
        toDateTimeLocal(share.expires_at),
    );
    const [pending, setPending] = useState<'update' | 'revoke' | null>(null);
    const rowId = `${share.recipient_type}-${share.id}`;

    const permissionLabels: Record<QuerySharePermission, string> = {
        view: t('sharing.permissionView'),
        execute: t('sharing.permissionExecute'),
        clone: t('sharing.permissionClone'),
        manage: t('sharing.permissionManage'),
    };
    const statusLabels: Record<string, string> = {
        pending: t('sharing.statusPending'),
        active: t('sharing.statusActive'),
        accepted: t('sharing.statusAccepted'),
        expired: t('sharing.statusExpired'),
        revoked: t('sharing.statusRevoked'),
    };

    async function updateShare() {
        setPending('update');
        onFeedback(null);

        try {
            await mutate(
                updateShareUrl(queryId, share.recipient_type, share.id),
                'PATCH',
                {
                    permission,
                    expires_at: toUtcIso(expiresAt),
                },
            );
            onFeedback({
                type: 'success',
                message: t('sharing.updateSuccess'),
            });
            onChanged();
        } catch (error) {
            onFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('sharing.updateError'),
            });
        } finally {
            setPending(null);
        }
    }

    async function revokeShare() {
        if (
            !window.confirm(
                t('sharing.revokeConfirm', { name: recipientName(share) }),
            )
        ) {
            return;
        }

        setPending('revoke');
        onFeedback(null);

        try {
            await mutate(
                destroyShareUrl(queryId, share.recipient_type, share.id),
                'DELETE',
            );
            onFeedback({
                type: 'success',
                message: t('sharing.revokeSuccess'),
            });
            onChanged();
        } catch (error) {
            onFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('sharing.revokeError'),
            });
        } finally {
            setPending(null);
        }
    }

    return (
        <li
            className="grid gap-4 border-b py-5 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_12rem_15rem_auto] lg:items-end"
            aria-busy={pending !== null}
        >
            <div className="min-w-0">
                <p className="flex items-center gap-2 truncate font-medium">
                    {share.recipient_type === 'user' ? (
                        <UserRound className="size-4 shrink-0 text-muted-foreground" />
                    ) : (
                        <UsersRound className="size-4 shrink-0 text-muted-foreground" />
                    )}
                    <span className="truncate">{recipientName(share)}</span>
                </p>
                <p className="truncate text-sm text-muted-foreground">
                    {share.recipient_type === 'user'
                        ? share.user.email
                        : t('sharing.groupMemberCount', {
                              count: share.group.member_count,
                          })}
                </p>
                <div className="mt-2 flex flex-wrap gap-2">
                    <Badge variant="outline">
                        {share.recipient_type === 'user'
                            ? t('sharing.recipientUserBadge')
                            : t('sharing.recipientGroupBadge')}
                    </Badge>
                    <Badge variant="outline">
                        {statusLabels[share.status] ?? share.status}
                    </Badge>
                </div>
            </div>

            <div className="grid gap-2">
                {share.can_update ? (
                    <Label htmlFor={`share-permission-${rowId}`}>
                        {t('sharing.permission')}
                    </Label>
                ) : (
                    <span className="text-sm leading-none font-medium">
                        {t('sharing.permission')}
                    </span>
                )}
                {share.can_update ? (
                    <Select
                        value={permission}
                        onValueChange={(value) =>
                            setPermission(value as QuerySharePermission)
                        }
                        disabled={pending !== null}
                    >
                        <SelectTrigger id={`share-permission-${rowId}`}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {permissions.map((value) => (
                                <SelectItem key={value} value={value}>
                                    {permissionLabels[value]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : (
                    <p className="flex h-9 items-center rounded-md border bg-muted/40 px-3 text-sm">
                        {permissionLabels[share.permission]}
                    </p>
                )}
            </div>

            <div className="grid gap-2">
                {share.can_update ? (
                    <Label htmlFor={`share-expiration-${rowId}`}>
                        {t('sharing.expiration')}
                    </Label>
                ) : (
                    <span className="text-sm leading-none font-medium">
                        {t('sharing.expiration')}
                    </span>
                )}
                {share.can_update ? (
                    <Input
                        id={`share-expiration-${rowId}`}
                        type="datetime-local"
                        value={expiresAt}
                        onChange={(event) => setExpiresAt(event.target.value)}
                        max={
                            maximumExpiration
                                ? toDateTimeLocal(maximumExpiration)
                                : undefined
                        }
                        disabled={pending !== null}
                    />
                ) : (
                    <p className="flex h-9 items-center rounded-md border bg-muted/40 px-3 text-sm">
                        {share.expires_at
                            ? formatDate(share.expires_at, {
                                  dateStyle: 'medium',
                                  timeStyle: 'short',
                              })
                            : t('sharing.noExpiration')}
                    </p>
                )}
            </div>

            <div className="flex flex-wrap gap-2 lg:justify-end">
                {share.can_update && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={updateShare}
                        disabled={pending !== null}
                    >
                        {pending === 'update' && (
                            <Spinner aria-label={t('common.loading')} />
                        )}
                        {t('common.save')}
                    </Button>
                )}
                {share.can_revoke && (
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={revokeShare}
                        disabled={pending !== null}
                    >
                        {pending === 'revoke' ? (
                            <Spinner aria-label={t('common.loading')} />
                        ) : (
                            <Trash2 />
                        )}
                        {t('sharing.revoke')}
                    </Button>
                )}
            </div>
        </li>
    );
}

function PendingInvitationRow({
    queryId,
    invitation,
    onChanged,
    onFeedback,
}: {
    queryId: number;
    invitation: QueryUserShareRecipient;
    onChanged: () => void;
    onFeedback: (feedback: Feedback) => void;
}) {
    const { t, formatDate } = useI18n();
    const [pending, setPending] = useState(false);
    const permissionLabels: Record<QuerySharePermission, string> = {
        view: t('sharing.permissionView'),
        execute: t('sharing.permissionExecute'),
        clone: t('sharing.permissionClone'),
        manage: t('sharing.permissionManage'),
    };

    async function cancelInvitation() {
        if (
            !window.confirm(
                t('sharing.cancelInvitationConfirm', {
                    name: invitation.user.name,
                }),
            )
        ) {
            return;
        }

        setPending(true);
        onFeedback(null);

        try {
            await mutate(
                destroyShareUrl(queryId, 'user', invitation.id),
                'DELETE',
            );
            onFeedback({
                type: 'success',
                message: t('sharing.cancelInvitationSuccess'),
            });
            onChanged();
        } catch (error) {
            onFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('sharing.cancelInvitationError'),
            });
        } finally {
            setPending(false);
        }
    }

    return (
        <li
            className="grid gap-4 border-b py-5 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_10rem_14rem_14rem_auto] lg:items-center"
            aria-busy={pending}
        >
            <div className="min-w-0">
                <p className="flex items-center gap-2 truncate font-medium">
                    <UserRound className="size-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{invitation.user.name}</span>
                </p>
                <p className="truncate text-sm text-muted-foreground">
                    {invitation.user.email}
                </p>
            </div>
            <div className="space-y-1">
                <p className="text-xs text-muted-foreground">
                    {t('sharing.permission')}
                </p>
                <p className="text-sm">
                    {permissionLabels[invitation.permission]}
                </p>
            </div>
            <div className="space-y-1">
                <p className="text-xs text-muted-foreground">
                    {t('sharing.responseDeadline')}
                </p>
                <p className="flex items-center gap-1.5 text-sm">
                    <CalendarClock className="size-4 text-muted-foreground" />
                    {invitation.respond_by
                        ? formatDate(invitation.respond_by, {
                              dateStyle: 'medium',
                              timeStyle: 'short',
                          })
                        : t('sharing.noResponseDeadline')}
                </p>
            </div>
            <div className="space-y-1">
                <p className="text-xs text-muted-foreground">
                    {t('sharing.accessExpiration')}
                </p>
                <p className="flex items-center gap-1.5 text-sm">
                    <CalendarClock className="size-4 text-muted-foreground" />
                    {invitation.expires_at
                        ? formatDate(invitation.expires_at, {
                              dateStyle: 'medium',
                              timeStyle: 'short',
                          })
                        : t('sharing.noExpiration')}
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                <Badge variant="secondary">{t('sharing.statusPending')}</Badge>
                {invitation.can_cancel && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={cancelInvitation}
                        disabled={pending}
                    >
                        {pending ? (
                            <Spinner aria-label={t('common.loading')} />
                        ) : (
                            <Trash2 />
                        )}
                        {t('sharing.cancelInvitation')}
                    </Button>
                )}
            </div>
        </li>
    );
}

export default function QuerySharing({
    query,
    permissions,
    groupPermissions,
    accessLevels,
    recipientType,
    historyRecipientType,
    search,
    candidates,
    pendingInvitations,
    activeShares,
    shareHistory,
}: SharingProps) {
    const { t, formatDate } = useI18n();
    const [accessLevel, setAccessLevel] = useState(query.access_level);
    const [candidateSearch, setCandidateSearch] = useState(search);
    const [selectedCandidate, setSelectedCandidate] = useState('');
    const [newPermission, setNewPermission] =
        useState<QuerySharePermission>('view');
    const [newExpiration, setNewExpiration] = useState('');
    const [newRespondBy, setNewRespondBy] = useState('');
    const [accessPending, setAccessPending] = useState(false);
    const [addPending, setAddPending] = useState(false);
    const [candidatePending, setCandidatePending] = useState(false);
    const [historyPending, setHistoryPending] = useState(false);
    const [feedback, setFeedback] = useState<Feedback>(null);

    const accessLevelLabels: Record<QueryAccessLevel, string> = {
        private: t('queries.accessLevelPrivate'),
        restricted: t('queries.accessLevelRestricted'),
        organization: t('queries.accessLevelOrganization'),
    };
    const accessLevelDescriptions: Record<QueryAccessLevel, string> = {
        private: t('queries.accessLevelPrivateDescription'),
        restricted: t('queries.accessLevelRestrictedDescription'),
        organization: t('queries.accessLevelOrganizationDescription'),
    };
    const permissionLabels: Record<QuerySharePermission, string> = {
        view: t('sharing.permissionView'),
        execute: t('sharing.permissionExecute'),
        clone: t('sharing.permissionClone'),
        manage: t('sharing.permissionManage'),
    };
    const statusLabels: Record<string, string> = {
        pending: t('sharing.statusPending'),
        active: t('sharing.statusActive'),
        accepted: t('sharing.statusAccepted'),
        cancelled: t('sharing.statusCancelled'),
        declined: t('sharing.statusDeclined'),
        expired: t('sharing.statusExpired'),
        revoked: t('sharing.statusRevoked'),
    };

    function reloadSharing() {
        router.reload({
            only: [
                'query',
                'candidates',
                'pendingInvitations',
                'activeShares',
                'shareHistory',
            ],
        });
    }

    function reloadSharingAfterAdd() {
        router.get(
            sharingUrl(query.id),
            {
                recipient_type: recipientType,
                history_recipient_type: historyRecipientType,
                search: search || undefined,
                candidate_page: 1,
                history_page: shareHistory.meta.current_page,
                per_page: candidates.meta.per_page,
            },
            {
                only: [
                    'query',
                    'candidates',
                    'pendingInvitations',
                    'activeShares',
                    'shareHistory',
                ],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function loadCandidates(
        page: number,
        nextSearch = candidateSearch,
        nextRecipientType = recipientType,
    ) {
        setSelectedCandidate('');
        setCandidatePending(true);
        router.get(
            sharingUrl(query.id),
            {
                recipient_type: nextRecipientType,
                history_recipient_type: historyRecipientType,
                search: nextSearch.trim() || undefined,
                candidate_page: page,
                history_page: shareHistory.meta.current_page,
                per_page: candidates.meta.per_page,
            },
            {
                only: ['recipientType', 'candidates', 'search'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setCandidatePending(false),
            },
        );
    }

    function searchCandidates(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        loadCandidates(1);
    }

    function changeRecipientType(value: QueryShareRecipientType) {
        if (value === recipientType) {
            return;
        }

        setCandidateSearch('');
        setSelectedCandidate('');
        setNewPermission('view');
        setNewRespondBy('');
        loadCandidates(1, '', value);
    }

    function loadHistory(
        page: number,
        nextHistoryRecipientType = historyRecipientType,
    ) {
        setHistoryPending(true);
        router.get(
            sharingUrl(query.id),
            {
                recipient_type: recipientType,
                history_recipient_type: nextHistoryRecipientType,
                search: candidateSearch.trim() || undefined,
                candidate_page: candidates.meta.current_page,
                history_page: page,
                per_page: shareHistory.meta.per_page,
            },
            {
                only: ['historyRecipientType', 'shareHistory'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setHistoryPending(false),
            },
        );
    }

    function changeHistoryRecipientType(value: QueryShareRecipientType) {
        if (value !== historyRecipientType) {
            loadHistory(1, value);
        }
    }

    async function updateAccessLevel() {
        if (!query.can_change_access_level) {
            return;
        }

        if (
            accessLevel === 'private' &&
            activeShares.length + pendingInvitations.length > 0 &&
            !window.confirm(
                t('sharing.privateConfirm', {
                    count: activeShares.length + pendingInvitations.length,
                }),
            )
        ) {
            return;
        }

        setAccessPending(true);
        setFeedback(null);

        try {
            await mutate(accessLevelUrl(query.id), 'PATCH', {
                access_level: accessLevel,
            });
            setFeedback({
                type: 'success',
                message: t('sharing.accessLevelSuccess'),
            });

            reloadSharing();
        } catch (error) {
            setAccessLevel(query.access_level);
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('sharing.accessLevelError'),
            });
        } finally {
            setAccessPending(false);
        }
    }

    async function addShare() {
        if (selectedCandidate === '') {
            setFeedback({
                type: 'error',
                message:
                    recipientType === 'user'
                        ? t('sharing.userCandidateRequired')
                        : t('sharing.groupCandidateRequired'),
            });

            return;
        }

        setAddPending(true);
        setFeedback(null);

        try {
            await mutate(storeShareUrl(query.id, recipientType), 'POST', {
                [recipientType === 'user' ? 'user_id' : 'group_id']:
                    Number(selectedCandidate),
                permission: newPermission,
                expires_at: toUtcIso(newExpiration),
                ...(recipientType === 'user'
                    ? { respond_by: toUtcIso(newRespondBy) }
                    : {}),
            });
            setSelectedCandidate('');
            setNewPermission('view');
            setNewExpiration('');
            setNewRespondBy('');

            if (recipientType === 'group' && query.access_level === 'private') {
                setAccessLevel('restricted');
            }

            setFeedback({
                type: 'success',
                message:
                    recipientType === 'user'
                        ? t('sharing.invitationSentSuccess')
                        : t('sharing.addSuccess'),
            });
            reloadSharingAfterAdd();
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('sharing.addError'),
            });
        } finally {
            setAddPending(false);
        }
    }

    return (
        <>
            <Head title={t('sharing.pageTitle', { name: query.name })} />

            <div className="px-6 py-6">
                <Heading
                    title={t('sharing.title')}
                    description={t('sharing.description', {
                        name: query.name,
                    })}
                    actions={
                        <Button asChild variant="outline">
                            <Link href={queries.show(query.id)}>
                                {t('sharing.backToQuery')}
                            </Link>
                        </Button>
                    }
                />

                <div className="space-y-6">
                    {feedback && (
                        <Alert
                            variant={
                                feedback.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                            role={
                                feedback.type === 'error' ? 'alert' : 'status'
                            }
                            aria-live="polite"
                        >
                            <ShieldCheck />
                            <AlertTitle>
                                {feedback.type === 'error'
                                    ? t('sharing.errorTitle')
                                    : t('sharing.successTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {feedback.message}
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card aria-busy={accessPending}>
                        <CardHeader>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="space-y-1.5">
                                    <CardTitle className="flex items-center gap-2">
                                        <Share2 className="size-4" />
                                        {t('sharing.accessLevelTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('sharing.accessLevelDescription')}
                                    </CardDescription>
                                </div>
                                <QueryAccessLevelBadge
                                    accessLevel={query.access_level}
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-[minmax(0,22rem)_1fr_auto] md:items-end">
                            <div className="grid gap-2">
                                <Label htmlFor="query-access-level">
                                    {t('queries.accessLevel')}
                                </Label>
                                <Select
                                    value={accessLevel}
                                    onValueChange={(value) =>
                                        setAccessLevel(
                                            value as QueryAccessLevel,
                                        )
                                    }
                                    disabled={
                                        accessPending ||
                                        !query.can_change_access_level
                                    }
                                >
                                    <SelectTrigger id="query-access-level">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accessLevels.map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {accessLevelLabels[value]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {accessLevelDescriptions[accessLevel]}
                            </p>
                            <Button
                                type="button"
                                onClick={updateAccessLevel}
                                disabled={
                                    accessPending ||
                                    !query.can_change_access_level ||
                                    (accessLevel === query.access_level &&
                                        !(
                                            accessLevel === 'private' &&
                                            activeShares.length > 0
                                        ))
                                }
                            >
                                {accessPending && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                {t('sharing.applyAccessLevel')}
                            </Button>
                            {accessLevel === 'organization' && (
                                <p className="text-xs text-muted-foreground md:col-span-3">
                                    {t('sharing.organizationBaseline')}
                                </p>
                            )}
                            {!query.can_change_access_level && (
                                <p className="text-xs text-muted-foreground md:col-span-3">
                                    {t('sharing.ownerOnlyAccessLevel')}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card aria-busy={candidatePending || addPending}>
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="space-y-1.5">
                                    <CardTitle className="flex items-center gap-2">
                                        <UserPlus className="size-4" />
                                        {t('sharing.addTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('sharing.addDescription')}
                                    </CardDescription>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Label
                                        htmlFor="share-recipient-type"
                                        className="sr-only"
                                    >
                                        {t('sharing.recipientType')}
                                    </Label>
                                    <Select
                                        value={recipientType}
                                        onValueChange={(value) =>
                                            changeRecipientType(
                                                value as QueryShareRecipientType,
                                            )
                                        }
                                        disabled={
                                            candidatePending || addPending
                                        }
                                    >
                                        <SelectTrigger
                                            id="share-recipient-type"
                                            className="w-40"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="user">
                                                {t('sharing.recipientTypeUser')}
                                            </SelectItem>
                                            <SelectItem value="group">
                                                {t(
                                                    'sharing.recipientTypeGroup',
                                                )}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Button asChild variant="outline">
                                        <Link href={groupRoutes.index()}>
                                            <UsersRound />
                                            {t('sharing.manageGroups')}
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <form
                                onSubmit={searchCandidates}
                                className="flex flex-col gap-2 sm:flex-row"
                                role="search"
                            >
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="share-candidate-search">
                                        {recipientType === 'user'
                                            ? t('sharing.searchUsers')
                                            : t('sharing.searchGroups')}
                                    </Label>
                                    <Input
                                        id="share-candidate-search"
                                        type="search"
                                        value={candidateSearch}
                                        onChange={(event) =>
                                            setCandidateSearch(
                                                event.target.value,
                                            )
                                        }
                                        placeholder={
                                            recipientType === 'user'
                                                ? t(
                                                      'sharing.searchUsersPlaceholder',
                                                  )
                                                : t(
                                                      'sharing.searchGroupsPlaceholder',
                                                  )
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="sm:self-end"
                                    disabled={candidatePending}
                                >
                                    {candidatePending ? (
                                        <Spinner
                                            aria-label={t('common.loading')}
                                        />
                                    ) : (
                                        <Search />
                                    )}
                                    {t('sharing.searchAction')}
                                </Button>
                            </form>

                            <div
                                className={
                                    recipientType === 'user'
                                        ? 'grid gap-4 xl:grid-cols-[minmax(0,1fr)_11rem_14rem_14rem_auto] xl:items-end'
                                        : 'grid gap-4 xl:grid-cols-[minmax(0,1fr)_12rem_15rem_auto] xl:items-end'
                                }
                            >
                                <div className="grid gap-2">
                                    <Label htmlFor="share-candidate">
                                        {t('sharing.recipient')}
                                    </Label>
                                    <Select
                                        value={selectedCandidate}
                                        onValueChange={setSelectedCandidate}
                                        disabled={
                                            candidatePending ||
                                            candidates.data.length === 0
                                        }
                                    >
                                        <SelectTrigger id="share-candidate">
                                            <SelectValue
                                                placeholder={
                                                    recipientType === 'user'
                                                        ? t(
                                                              'sharing.selectUser',
                                                          )
                                                        : t(
                                                              'sharing.selectGroup',
                                                          )
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {candidates.data.map(
                                                (candidate) => (
                                                    <SelectItem
                                                        key={`${candidate.recipient_type}-${candidate.id}`}
                                                        value={String(
                                                            candidate.id,
                                                        )}
                                                    >
                                                        {candidate.name} —{' '}
                                                        {candidate.recipient_type ===
                                                        'user'
                                                            ? candidate.email
                                                            : t(
                                                                  'sharing.groupMemberCount',
                                                                  {
                                                                      count: candidate.member_count,
                                                                  },
                                                              )}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="new-share-permission">
                                        {t('sharing.permission')}
                                    </Label>
                                    <Select
                                        value={newPermission}
                                        onValueChange={(value) =>
                                            setNewPermission(
                                                value as QuerySharePermission,
                                            )
                                        }
                                        disabled={addPending}
                                    >
                                        <SelectTrigger id="new-share-permission">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(recipientType === 'user'
                                                ? permissions
                                                : groupPermissions
                                            ).map((value) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {permissionLabels[value]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {recipientType === 'user' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="new-share-respond-by">
                                            {t('sharing.responseDeadline')}
                                        </Label>
                                        <Input
                                            id="new-share-respond-by"
                                            type="datetime-local"
                                            value={newRespondBy}
                                            onChange={(event) =>
                                                setNewRespondBy(
                                                    event.target.value,
                                                )
                                            }
                                            max={
                                                newExpiration ||
                                                (query.delegation_expires_at
                                                    ? toDateTimeLocal(
                                                          query.delegation_expires_at,
                                                      )
                                                    : undefined)
                                            }
                                            disabled={addPending}
                                        />
                                    </div>
                                )}
                                <div className="grid gap-2">
                                    <Label htmlFor="new-share-expiration">
                                        {t('sharing.accessExpiration')}
                                    </Label>
                                    <Input
                                        id="new-share-expiration"
                                        type="datetime-local"
                                        value={newExpiration}
                                        onChange={(event) =>
                                            setNewExpiration(event.target.value)
                                        }
                                        max={
                                            query.delegation_expires_at
                                                ? toDateTimeLocal(
                                                      query.delegation_expires_at,
                                                  )
                                                : undefined
                                        }
                                        disabled={addPending}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    onClick={addShare}
                                    disabled={
                                        addPending ||
                                        selectedCandidate === '' ||
                                        (query.delegation_expires_at !== null &&
                                            newExpiration === '')
                                    }
                                >
                                    {addPending ? (
                                        <Spinner
                                            aria-label={t('common.loading')}
                                        />
                                    ) : recipientType === 'user' ? (
                                        <Send />
                                    ) : (
                                        <UsersRound />
                                    )}
                                    {recipientType === 'user'
                                        ? t('sharing.sendInvitation')
                                        : t('sharing.shareWithGroup')}
                                </Button>
                            </div>

                            {query.delegation_expires_at && (
                                <p className="text-xs text-muted-foreground">
                                    {t('sharing.delegationLimit', {
                                        date: formatDate(
                                            query.delegation_expires_at,
                                            {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            },
                                        ),
                                    })}
                                </p>
                            )}

                            {recipientType === 'group' && (
                                <p className="text-xs text-muted-foreground">
                                    {t('sharing.groupPermissionHint')}
                                </p>
                            )}

                            {recipientType === 'user' && (
                                <p className="text-xs text-muted-foreground">
                                    {t('sharing.invitationHint')}
                                </p>
                            )}

                            {candidates.data.length === 0 && (
                                <p
                                    className="rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground"
                                    role="status"
                                >
                                    {recipientType === 'user'
                                        ? t('sharing.noUserCandidates')
                                        : t('sharing.noGroupCandidates')}
                                </p>
                            )}

                            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                <span>
                                    {t(
                                        recipientType === 'user'
                                            ? 'sharing.userCandidateCount'
                                            : 'sharing.groupCandidateCount',
                                        { count: candidates.meta.total },
                                    )}
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadCandidates(
                                                candidates.meta.current_page -
                                                    1,
                                            )
                                        }
                                        disabled={
                                            candidatePending ||
                                            candidates.meta.current_page <= 1
                                        }
                                        aria-label={t('queries.previous')}
                                    >
                                        <ChevronLeft />
                                    </Button>
                                    <span>
                                        {t('queries.page')}{' '}
                                        {candidates.meta.current_page} /{' '}
                                        {candidates.meta.last_page}
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadCandidates(
                                                candidates.meta.current_page +
                                                    1,
                                            )
                                        }
                                        disabled={
                                            candidatePending ||
                                            candidates.meta.current_page >=
                                                candidates.meta.last_page
                                        }
                                        aria-label={t('queries.next')}
                                    >
                                        <ChevronRight />
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Send className="size-4" />
                                {t('sharing.pendingInvitationsTitle')}
                            </CardTitle>
                            <CardDescription>
                                {t('sharing.pendingInvitationsDescription')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {pendingInvitations.length === 0 ? (
                                <p
                                    className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                                    role="status"
                                >
                                    {t('sharing.noPendingInvitations')}
                                </p>
                            ) : (
                                <ul>
                                    {pendingInvitations.map((invitation) =>
                                        invitation.recipient_type === 'user' ? (
                                            <PendingInvitationRow
                                                key={`user-${invitation.id}-${invitation.permission}-${invitation.respond_by ?? ''}`}
                                                queryId={query.id}
                                                invitation={invitation}
                                                onChanged={reloadSharing}
                                                onFeedback={setFeedback}
                                            />
                                        ) : null,
                                    )}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="size-4" />
                                {t('sharing.activeTitle')}
                            </CardTitle>
                            <CardDescription>
                                {t('sharing.activeDescription')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {activeShares.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    {t('sharing.noActiveShares')}
                                </p>
                            ) : (
                                <ul>
                                    {activeShares.map((share) => (
                                        <ShareRow
                                            key={`${share.recipient_type}-${share.id}-${share.permission}-${share.expires_at ?? ''}`}
                                            queryId={query.id}
                                            share={share}
                                            permissions={
                                                share.recipient_type === 'user'
                                                    ? permissions
                                                    : groupPermissions
                                            }
                                            maximumExpiration={
                                                query.delegation_expires_at
                                            }
                                            onChanged={reloadSharing}
                                            onFeedback={setFeedback}
                                        />
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card aria-busy={historyPending}>
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="space-y-1.5">
                                    <CardTitle className="flex items-center gap-2">
                                        <History className="size-4" />
                                        {t('sharing.historyTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('sharing.historyDescription')}
                                    </CardDescription>
                                </div>
                                <div>
                                    <Label
                                        htmlFor="share-history-recipient-type"
                                        className="sr-only"
                                    >
                                        {t('sharing.historyRecipientType')}
                                    </Label>
                                    <Select
                                        value={historyRecipientType}
                                        onValueChange={(value) =>
                                            changeHistoryRecipientType(
                                                value as QueryShareRecipientType,
                                            )
                                        }
                                        disabled={historyPending}
                                    >
                                        <SelectTrigger
                                            id="share-history-recipient-type"
                                            className="w-40"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="user">
                                                {t('sharing.recipientTypeUser')}
                                            </SelectItem>
                                            <SelectItem value="group">
                                                {t(
                                                    'sharing.recipientTypeGroup',
                                                )}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {shareHistory.data.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    {t('sharing.noHistory')}
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {shareHistory.data.map((share) => (
                                        <li
                                            key={`${share.recipient_type}-${share.id}`}
                                            className="grid gap-2 py-4 md:grid-cols-[minmax(0,1fr)_10rem_12rem_12rem] md:items-center"
                                        >
                                            <div className="min-w-0">
                                                <p className="flex items-center gap-2 truncate font-medium">
                                                    {share.recipient_type ===
                                                    'user' ? (
                                                        <UserRound className="size-4 shrink-0 text-muted-foreground" />
                                                    ) : (
                                                        <UsersRound className="size-4 shrink-0 text-muted-foreground" />
                                                    )}
                                                    <span className="truncate">
                                                        {recipientName(share)}
                                                    </span>
                                                </p>
                                                <p className="truncate text-sm text-muted-foreground">
                                                    {share.recipient_type ===
                                                    'user'
                                                        ? share.user.email
                                                        : t(
                                                              'sharing.groupMemberCount',
                                                              {
                                                                  count: share
                                                                      .group
                                                                      .member_count,
                                                              },
                                                          )}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    share.is_active
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                                className="w-fit"
                                            >
                                                {statusLabels[share.status] ??
                                                    share.status}
                                            </Badge>
                                            <span className="text-sm">
                                                {
                                                    permissionLabels[
                                                        share.permission
                                                    ]
                                                }
                                            </span>
                                            <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                                <CalendarClock className="size-4" />
                                                {share.recipient_type ===
                                                    'user' &&
                                                share.accepted_at === null &&
                                                share.respond_by
                                                    ? t(
                                                          'sharing.responseDeadlineValue',
                                                          {
                                                              date: formatDate(
                                                                  share.respond_by,
                                                                  {
                                                                      dateStyle:
                                                                          'medium',
                                                                      timeStyle:
                                                                          'short',
                                                                  },
                                                              ),
                                                          },
                                                      )
                                                    : share.expires_at
                                                      ? formatDate(
                                                            share.expires_at,
                                                            {
                                                                dateStyle:
                                                                    'medium',
                                                                timeStyle:
                                                                    'short',
                                                            },
                                                        )
                                                      : t(
                                                            'sharing.noExpiration',
                                                        )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                <span>
                                    {t('sharing.historyCount', {
                                        count: shareHistory.meta.total,
                                    })}
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadHistory(
                                                shareHistory.meta.current_page -
                                                    1,
                                            )
                                        }
                                        disabled={
                                            historyPending ||
                                            shareHistory.meta.current_page <= 1
                                        }
                                        aria-label={t('queries.previous')}
                                    >
                                        <ChevronLeft />
                                    </Button>
                                    <span>
                                        {t('queries.page')}{' '}
                                        {shareHistory.meta.current_page} /{' '}
                                        {shareHistory.meta.last_page}
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadHistory(
                                                shareHistory.meta.current_page +
                                                    1,
                                            )
                                        }
                                        disabled={
                                            historyPending ||
                                            shareHistory.meta.current_page >=
                                                shareHistory.meta.last_page
                                        }
                                        aria-label={t('queries.next')}
                                    >
                                        <ChevronRight />
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

QuerySharing.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Partage', href: '#' },
    ],
};
