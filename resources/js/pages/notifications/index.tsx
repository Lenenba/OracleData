import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    BellRing,
    CalendarClock,
    Check,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    Inbox,
    MessageSquareText,
    X,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { ChangeRequestStatusBadge } from '@/components/queries/change-request-status-badge';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import notificationRoutes from '@/routes/notifications';
import queries from '@/routes/queries';
import queryShareInvitations from '@/routes/query-share-invitations';
import type {
    AppNotification,
    AppNotificationKind,
    NotificationFilter,
    PaginatedNotifications,
    QueryChangeRequestStatus,
    QuerySharePermission,
} from '@/types';

type NotificationsProps = {
    notifications: PaginatedNotifications;
    filter: NotificationFilter;
};

type Feedback = { type: 'success' | 'error'; message: string } | null;

type MutationRoute = {
    url: string;
    method: string;
};

const changeRequestShowUrl = (queryId: number, requestId: number) =>
    `/queries/${queryId}/change-requests/${requestId}`;

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

async function mutate(route: MutationRoute): Promise<void> {
    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCsrfToken(),
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error((await errorMessage(response)) ?? 'Request failed.');
    }
}

function isShareKind(kind: AppNotificationKind) {
    return (
        kind === 'query_share_invitation' ||
        kind === 'query_share_invitation_accepted' ||
        kind === 'query_share_invitation_declined'
    );
}

function isChangeRequestKind(kind: AppNotificationKind) {
    return (
        kind === 'query_change_request_created' ||
        kind === 'query_change_request_commented' ||
        kind === 'query_change_request_mentioned' ||
        kind === 'query_change_request_status_changed'
    );
}

export default function NotificationsIndex({
    notifications,
    filter,
}: NotificationsProps) {
    const { t, formatDate } = useI18n();
    const { notificationSummary } = usePage().props;
    const [navigationPending, setNavigationPending] = useState(false);
    const [actionPending, setActionPending] = useState<string | null>(null);
    const [declineTarget, setDeclineTarget] = useState<AppNotification | null>(
        null,
    );
    const [feedback, setFeedback] = useState<Feedback>(null);

    const permissionLabels: Record<QuerySharePermission, string> = {
        view: t('sharing.permissionView'),
        execute: t('sharing.permissionExecute'),
        clone: t('sharing.permissionClone'),
        manage: t('sharing.permissionManage'),
    };
    const changeRequestStatusLabels: Record<QueryChangeRequestStatus, string> =
        {
            pending: t('changeRequests.statusPending'),
            accepted: t('changeRequests.statusAccepted'),
            rejected: t('changeRequests.statusRejected'),
            completed: t('changeRequests.statusCompleted'),
            cancelled: t('changeRequests.statusCancelled'),
        };

    function loadNotifications(
        page: number,
        nextFilter: NotificationFilter = filter,
    ) {
        setNavigationPending(true);
        router.get(
            notificationRoutes.index.url(),
            {
                filter: nextFilter,
                page,
                per_page: notifications.meta.per_page,
            },
            {
                only: ['notifications', 'filter', 'notificationSummary'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setNavigationPending(false),
            },
        );
    }

    function reloadAfterMutation() {
        loadNotifications(
            filter === 'unread' ? 1 : notifications.meta.current_page,
        );
    }

    async function markRead(notification: AppNotification) {
        setActionPending(`read-${notification.id}`);
        setFeedback(null);

        try {
            await mutate(notificationRoutes.read(notification.id));
            reloadAfterMutation();
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('notifications.readError'),
            });
        } finally {
            setActionPending(null);
        }
    }

    async function markAllRead() {
        setActionPending('read-all');
        setFeedback(null);

        try {
            await mutate(notificationRoutes.readAll());
            setFeedback({
                type: 'success',
                message: t('notifications.readAllSuccess'),
            });
            loadNotifications(1);
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('notifications.readAllError'),
            });
        } finally {
            setActionPending(null);
        }
    }

    async function respond(
        notification: AppNotification,
        response: 'accept' | 'decline',
    ) {
        if (
            notification.kind !== 'query_share_invitation' ||
            !notification.share
        ) {
            return;
        }

        setDeclineTarget(null);
        setActionPending(`${response}-${notification.id}`);
        setFeedback(null);

        try {
            await mutate(
                response === 'accept'
                    ? queryShareInvitations.accept(notification.share.id)
                    : queryShareInvitations.decline(notification.share.id),
            );

            if (!notification.read_at) {
                await mutate(notificationRoutes.read(notification.id)).catch(
                    () => undefined,
                );
            }

            setFeedback({
                type: 'success',
                message:
                    response === 'accept'
                        ? t('notifications.acceptSuccess')
                        : t('notifications.declineSuccess'),
            });
            reloadAfterMutation();
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('notifications.responseError'),
            });
            reloadAfterMutation();
        } finally {
            setActionPending(null);
        }
    }

    function actorName(notification: AppNotification) {
        return (
            notification.actor?.name ??
            notification.share?.invited_by?.name ??
            t('notifications.unknownActor')
        );
    }

    function queryName(notification: AppNotification) {
        return (
            notification.query?.name ??
            notification.share?.query?.name ??
            t('notifications.unknownQuery')
        );
    }

    function notificationTitle(notification: AppNotification): string {
        if (notification.kind === 'query_share_invitation_accepted') {
            return t('notifications.responseAcceptedTitle');
        }

        if (notification.kind === 'query_share_invitation_declined') {
            return t('notifications.responseDeclinedTitle');
        }

        if (notification.kind === 'query_change_request_created') {
            return t('notifications.changeRequestCreatedTitle');
        }

        if (notification.kind === 'query_change_request_commented') {
            return t('notifications.changeRequestCommentedTitle');
        }

        if (notification.kind === 'query_change_request_mentioned') {
            return t('notifications.changeRequestMentionedTitle');
        }

        if (notification.kind === 'query_change_request_status_changed') {
            return t('notifications.changeRequestStatusTitle');
        }

        const share = notification.share;

        if (!share) {
            return t('notifications.genericTitle');
        }

        if (share.is_expired) {
            return t('notifications.invitationExpiredTitle');
        }

        if (share.status === 'accepted' || share.status === 'active') {
            return t('notifications.invitationAcceptedTitle');
        }

        if (share.status === 'declined') {
            return t('notifications.invitationDeclinedTitle');
        }

        if (share.status === 'cancelled' || share.status === 'revoked') {
            return t('notifications.invitationCancelledTitle');
        }

        return t('notifications.invitationReceivedTitle');
    }

    function notificationDescription(notification: AppNotification): string {
        const name = queryName(notification);

        if (notification.kind === 'query_share_invitation_accepted') {
            return t('notifications.responseAcceptedDescription', {
                actor: actorName(notification),
                query: name,
            });
        }

        if (notification.kind === 'query_share_invitation_declined') {
            return t('notifications.responseDeclinedDescription', {
                actor: actorName(notification),
                query: name,
            });
        }

        if (notification.kind === 'query_change_request_created') {
            return t('notifications.changeRequestCreatedDescription', {
                actor: actorName(notification),
                query: name,
            });
        }

        if (notification.kind === 'query_change_request_commented') {
            return t('notifications.changeRequestCommentedDescription', {
                actor: actorName(notification),
                request:
                    notification.change_request?.title ??
                    t('notifications.unknownChangeRequest'),
            });
        }

        if (notification.kind === 'query_change_request_mentioned') {
            return t('notifications.changeRequestMentionedDescription', {
                actor: actorName(notification),
                request:
                    notification.change_request?.title ??
                    t('notifications.unknownChangeRequest'),
            });
        }

        if (notification.kind === 'query_change_request_status_changed') {
            return t('notifications.changeRequestStatusDescription', {
                actor: actorName(notification),
                request:
                    notification.change_request?.title ??
                    t('notifications.unknownChangeRequest'),
                status: notification.change_request
                    ? changeRequestStatusLabels[
                          notification.change_request.status
                      ]
                    : t('notifications.statusInformation'),
            });
        }

        const share = notification.share;

        if (!share) {
            return notification.is_available
                ? t('notifications.genericDescription')
                : t('notifications.unavailableDescription');
        }

        if (share.is_expired) {
            return t('notifications.invitationExpiredDescription', {
                query: name,
            });
        }

        if (share.status === 'accepted' || share.status === 'active') {
            return t('notifications.invitationAcceptedDescription', {
                query: name,
            });
        }

        if (share.status === 'declined') {
            return t('notifications.invitationDeclinedDescription', {
                query: name,
            });
        }

        if (share.status === 'cancelled' || share.status === 'revoked') {
            return t('notifications.invitationCancelledDescription', {
                query: name,
            });
        }

        return t('notifications.invitationReceivedDescription', {
            inviter: actorName(notification),
            query: name,
            permission: permissionLabels[share.permission],
        });
    }

    function notificationStatus(notification: AppNotification): string {
        if (
            isChangeRequestKind(notification.kind) &&
            notification.change_request
        ) {
            return changeRequestStatusLabels[
                notification.change_request.status
            ];
        }

        if (
            notification.kind === 'query_share_invitation_accepted' ||
            notification.kind === 'query_share_invitation_declined'
        ) {
            return notification.kind === 'query_share_invitation_accepted'
                ? t('sharing.statusAccepted')
                : t('sharing.statusDeclined');
        }

        const share = notification.share;

        if (!share) {
            return notification.is_available
                ? t('notifications.statusInformation')
                : t('notifications.statusUnavailable');
        }

        if (share.is_expired) {
            return t('sharing.statusExpired');
        }

        if (share.status === 'declined') {
            return t('sharing.statusDeclined');
        }

        if (share.status === 'cancelled') {
            return t('sharing.statusCancelled');
        }

        if (share.status === 'revoked') {
            return t('sharing.statusRevoked');
        }

        if (share.status === 'accepted' || share.status === 'active') {
            return t('sharing.statusAccepted');
        }

        return t('sharing.statusPending');
    }

    function notificationLink(notification: AppNotification) {
        if (!notification.is_available) {
            return null;
        }

        if (
            isChangeRequestKind(notification.kind) &&
            notification.query &&
            notification.change_request?.can_view &&
            !notification.query.is_archived
        ) {
            return changeRequestShowUrl(
                notification.query.id,
                notification.change_request.id,
            );
        }

        const query = notification.query ?? notification.share?.query;

        if (
            isShareKind(notification.kind) &&
            query &&
            !query.is_archived &&
            notification.kind !== 'query_share_invitation'
        ) {
            return queries.show.url(query.id);
        }

        if (
            notification.kind === 'query_share_invitation' &&
            query &&
            !query.is_archived &&
            notification.share &&
            (notification.share.status === 'accepted' ||
                notification.share.status === 'active')
        ) {
            return queries.show.url(query.id);
        }

        return null;
    }

    const unreadCount = notificationSummary?.unread_count ?? 0;

    return (
        <>
            <Head title={t('notifications.pageTitle')} />

            <div className="p-5">
                <Heading
                    title={t('notifications.title')}
                    description={t('notifications.description')}
                />

                <div className="mb-5 grid gap-5 md:grid-cols-3">
                    {[
                        {
                            label: 'Notifications',
                            value: notifications.meta.total,
                            icon: Inbox,
                            tone: 'bg-primary/15 text-primary',
                        },
                        {
                            label: 'Non lues',
                            value: unreadCount,
                            icon: BellRing,
                            tone: 'bg-destructive/15 text-destructive',
                        },
                        {
                            label: 'Collaborations',
                            value: notifications.data.filter(
                                (item) =>
                                    item.kind.startsWith(
                                        'query_change_request_',
                                    ) || item.kind.startsWith('query_share_'),
                            ).length,
                            icon: MessageSquareText,
                            tone: 'bg-purple/15 text-purple',
                        },
                    ].map(({ label, value, icon: Icon, tone }) => (
                        <div key={label} className="card">
                            <div className="card-body flex items-center gap-4">
                                <span
                                    className={`grid size-10 place-items-center rounded-full ${tone}`}
                                >
                                    <Icon className="size-4.5" />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        {label}
                                    </p>
                                    <strong className="mt-1 block text-2xl font-semibold tabular-nums">
                                        {value}
                                    </strong>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="space-y-5">
                    {feedback && (
                        <Alert
                            variant={
                                feedback.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                            aria-live="polite"
                        >
                            <BellRing aria-hidden="true" />
                            <AlertTitle>
                                {feedback.type === 'error'
                                    ? t('notifications.errorTitle')
                                    : t('notifications.successTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {feedback.message}
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card aria-busy={navigationPending}>
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="space-y-1.5">
                                    <CardTitle className="flex items-center gap-2">
                                        <Inbox aria-hidden="true" />
                                        {t('notifications.inboxTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('notifications.inboxDescription', {
                                            count: unreadCount,
                                        })}
                                    </CardDescription>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <div
                                        className="flex rounded-md border p-1"
                                        role="group"
                                        aria-label={t(
                                            'notifications.filterLabel',
                                        )}
                                    >
                                        {(['all', 'unread'] as const).map(
                                            (value) => (
                                                <Button
                                                    key={value}
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        filter === value
                                                            ? 'secondary'
                                                            : 'ghost'
                                                    }
                                                    onClick={() =>
                                                        loadNotifications(
                                                            1,
                                                            value,
                                                        )
                                                    }
                                                    disabled={navigationPending}
                                                    aria-pressed={
                                                        filter === value
                                                    }
                                                >
                                                    {value === 'all'
                                                        ? t(
                                                              'notifications.filterAll',
                                                          )
                                                        : t(
                                                              'notifications.filterUnread',
                                                          )}
                                                </Button>
                                            ),
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={markAllRead}
                                        disabled={
                                            actionPending === 'read-all' ||
                                            unreadCount === 0
                                        }
                                    >
                                        {actionPending === 'read-all' ? (
                                            <Spinner
                                                aria-label={t('common.loading')}
                                            />
                                        ) : (
                                            <CheckCheck aria-hidden="true" />
                                        )}
                                        {t('notifications.markAllRead')}
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {notifications.data.length === 0 ? (
                                <div className="rounded-lg border border-dashed px-4 py-10 text-center">
                                    <Inbox
                                        className="mx-auto mb-3 size-8 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <p className="font-medium">
                                        {filter === 'unread'
                                            ? t('notifications.noUnreadTitle')
                                            : t('notifications.emptyTitle')}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {filter === 'unread'
                                            ? t(
                                                  'notifications.noUnreadDescription',
                                              )
                                            : t(
                                                  'notifications.emptyDescription',
                                              )}
                                    </p>
                                    {filter === 'unread' && (
                                        <Button
                                            type="button"
                                            variant="link"
                                            onClick={() =>
                                                loadNotifications(1, 'all')
                                            }
                                        >
                                            {t('notifications.showAll')}
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {notifications.data.map((notification) => {
                                        const share = notification.share;
                                        const query =
                                            notification.query ??
                                            share?.query ??
                                            null;
                                        const isUnread =
                                            notification.read_at === null;
                                        const responding =
                                            actionPending ===
                                                `accept-${notification.id}` ||
                                            actionPending ===
                                                `decline-${notification.id}`;
                                        const link =
                                            notificationLink(notification);

                                        return (
                                            <li
                                                key={notification.id}
                                                className={`flex flex-col gap-4 p-4 md:flex-row md:items-start md:justify-between ${
                                                    isUnread
                                                        ? 'bg-primary/[0.035]'
                                                        : ''
                                                }`}
                                            >
                                                <div className="min-w-0 space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {isChangeRequestKind(
                                                            notification.kind,
                                                        ) ? (
                                                            <MessageSquareText
                                                                className="size-4 text-muted-foreground"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <BellRing
                                                                className="size-4 text-muted-foreground"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        <h2 className="font-medium">
                                                            {notificationTitle(
                                                                notification,
                                                            )}
                                                        </h2>
                                                        {isChangeRequestKind(
                                                            notification.kind,
                                                        ) &&
                                                        notification.change_request ? (
                                                            <ChangeRequestStatusBadge
                                                                status={
                                                                    notification
                                                                        .change_request
                                                                        .status
                                                                }
                                                            />
                                                        ) : (
                                                            <Badge variant="outline">
                                                                {notificationStatus(
                                                                    notification,
                                                                )}
                                                            </Badge>
                                                        )}
                                                        {isUnread && (
                                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-primary">
                                                                <span
                                                                    className="size-2 rounded-full bg-primary"
                                                                    aria-hidden="true"
                                                                />
                                                                {t(
                                                                    'notifications.unread',
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>

                                                    <p className="text-sm text-muted-foreground">
                                                        {notificationDescription(
                                                            notification,
                                                        )}
                                                    </p>

                                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                        <time
                                                            dateTime={
                                                                notification.created_at
                                                            }
                                                        >
                                                            {formatDate(
                                                                notification.created_at,
                                                                {
                                                                    dateStyle:
                                                                        'medium',
                                                                    timeStyle:
                                                                        'short',
                                                                },
                                                            )}
                                                        </time>
                                                        {share?.respond_by &&
                                                            notification.kind ===
                                                                'query_share_invitation' && (
                                                                <span className="flex items-center gap-1">
                                                                    <CalendarClock
                                                                        className="size-3.5"
                                                                        aria-hidden="true"
                                                                    />
                                                                    {t(
                                                                        'notifications.respondBy',
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
                                                                    )}
                                                                </span>
                                                            )}
                                                        {query?.is_archived && (
                                                            <span className="flex items-center gap-1">
                                                                <Archive
                                                                    className="size-3.5"
                                                                    aria-hidden="true"
                                                                />
                                                                {t(
                                                                    'notifications.queryArchived',
                                                                )}
                                                            </span>
                                                        )}
                                                        {!notification.is_available && (
                                                            <span>
                                                                {t(
                                                                    'notifications.statusUnavailable',
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2 md:max-w-80 md:justify-end">
                                                    {notification.kind ===
                                                        'query_share_invitation' &&
                                                        share?.can_accept &&
                                                        query !== null &&
                                                        !query.is_archived && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                onClick={() =>
                                                                    respond(
                                                                        notification,
                                                                        'accept',
                                                                    )
                                                                }
                                                                disabled={
                                                                    responding ||
                                                                    navigationPending
                                                                }
                                                                aria-label={t(
                                                                    'notifications.acceptFor',
                                                                    {
                                                                        query: query.name,
                                                                    },
                                                                )}
                                                            >
                                                                {actionPending ===
                                                                `accept-${notification.id}` ? (
                                                                    <Spinner
                                                                        aria-label={t(
                                                                            'common.loading',
                                                                        )}
                                                                    />
                                                                ) : (
                                                                    <Check aria-hidden="true" />
                                                                )}
                                                                {t(
                                                                    'notifications.accept',
                                                                )}
                                                            </Button>
                                                        )}
                                                    {notification.kind ===
                                                        'query_share_invitation' &&
                                                        share?.can_decline &&
                                                        query !== null &&
                                                        !query.is_archived && (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setDeclineTarget(
                                                                        notification,
                                                                    )
                                                                }
                                                                disabled={
                                                                    responding ||
                                                                    navigationPending
                                                                }
                                                                aria-label={t(
                                                                    'notifications.declineFor',
                                                                    {
                                                                        query: query.name,
                                                                    },
                                                                )}
                                                            >
                                                                {actionPending ===
                                                                `decline-${notification.id}` ? (
                                                                    <Spinner
                                                                        aria-label={t(
                                                                            'common.loading',
                                                                        )}
                                                                    />
                                                                ) : (
                                                                    <X aria-hidden="true" />
                                                                )}
                                                                {t(
                                                                    'notifications.decline',
                                                                )}
                                                            </Button>
                                                        )}
                                                    {link && (
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            <Link href={link}>
                                                                {isChangeRequestKind(
                                                                    notification.kind,
                                                                )
                                                                    ? t(
                                                                          'notifications.viewChangeRequest',
                                                                      )
                                                                    : t(
                                                                          'notifications.viewQuery',
                                                                      )}
                                                            </Link>
                                                        </Button>
                                                    )}
                                                    {isUnread && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                markRead(
                                                                    notification,
                                                                )
                                                            }
                                                            disabled={
                                                                actionPending ===
                                                                    `read-${notification.id}` ||
                                                                navigationPending
                                                            }
                                                            aria-label={t(
                                                                'notifications.markReadFor',
                                                                {
                                                                    title: notificationTitle(
                                                                        notification,
                                                                    ),
                                                                },
                                                            )}
                                                        >
                                                            {actionPending ===
                                                            `read-${notification.id}` ? (
                                                                <Spinner
                                                                    aria-label={t(
                                                                        'common.loading',
                                                                    )}
                                                                />
                                                            ) : (
                                                                <Check aria-hidden="true" />
                                                            )}
                                                            {t(
                                                                'notifications.markRead',
                                                            )}
                                                        </Button>
                                                    )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}

                            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                <span>
                                    {t('notifications.resultCount', {
                                        count: notifications.meta.total,
                                    })}
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadNotifications(
                                                notifications.meta
                                                    .current_page - 1,
                                            )
                                        }
                                        disabled={
                                            navigationPending ||
                                            notifications.meta.current_page <= 1
                                        }
                                        aria-label={t('queries.previous')}
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                    </Button>
                                    <span>
                                        {t('queries.page')}{' '}
                                        {notifications.meta.current_page} /{' '}
                                        {notifications.meta.last_page}
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            loadNotifications(
                                                notifications.meta
                                                    .current_page + 1,
                                            )
                                        }
                                        disabled={
                                            navigationPending ||
                                            notifications.meta.current_page >=
                                                notifications.meta.last_page
                                        }
                                        aria-label={t('queries.next')}
                                    >
                                        <ChevronRight aria-hidden="true" />
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog
                open={declineTarget !== null}
                onOpenChange={(open) => !open && setDeclineTarget(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('notifications.declineConfirmTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('notifications.declineConfirmDescription', {
                                query:
                                    declineTarget?.query?.name ??
                                    declineTarget?.share?.query?.name ??
                                    t('notifications.unknownQuery'),
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeclineTarget(null)}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() =>
                                declineTarget &&
                                respond(declineTarget, 'decline')
                            }
                        >
                            <X aria-hidden="true" />
                            {t('notifications.confirmDecline')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
