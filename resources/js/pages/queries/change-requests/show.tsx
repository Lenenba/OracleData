import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    Check,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    MessageCircle,
    Send,
    UserRound,
    X,
} from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { ChangeRequestStatusBadge } from '@/components/queries/change-request-status-badge';
import { MentionSelector } from '@/components/queries/mention-selector';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import queries from '@/routes/queries';
import type {
    PaginatedQueryChangeRequestComments,
    QueryChangeRequestComment,
    QueryChangeRequestQuery,
    QueryChangeRequestStatus,
    QueryChangeRequestSummary,
    QueryChangeRequestUser,
} from '@/types/change-requests';

type ShowProps = {
    query: QueryChangeRequestQuery;
    changeRequest: QueryChangeRequestSummary;
    comments: QueryChangeRequestComment[] | PaginatedQueryChangeRequestComments;
    mentionCandidates:
        | QueryChangeRequestUser[]
        | { data: QueryChangeRequestUser[] };
};

type Feedback = { type: 'success' | 'error'; message: string } | null;

type StatusAction = Exclude<QueryChangeRequestStatus, 'pending'>;

const changeRequestsIndexUrl = (queryId: number) =>
    `/queries/${queryId}/change-requests`;
const changeRequestShowUrl = (queryId: number, requestId: number) =>
    `/queries/${queryId}/change-requests/${requestId}`;
const changeRequestCommentsUrl = (queryId: number, requestId: number) =>
    `/queries/${queryId}/change-requests/${requestId}/comments`;

function validationMessage(errors: Record<string, string>, fallback: string) {
    return Object.values(errors)[0] ?? fallback;
}

export default function QueryChangeRequestShow({
    query,
    changeRequest,
    comments,
    mentionCandidates,
}: ShowProps) {
    const { t, formatDate } = useI18n();
    const candidates = Array.isArray(mentionCandidates)
        ? mentionCandidates
        : mentionCandidates.data;
    const commentPage = Array.isArray(comments) ? null : comments;
    const commentItems = Array.isArray(comments) ? comments : comments.data;
    const [body, setBody] = useState('');
    const [mentionedUserIds, setMentionedUserIds] = useState<number[]>([]);
    const [commentPending, setCommentPending] = useState(false);
    const [navigationPending, setNavigationPending] = useState(false);
    const [statusPending, setStatusPending] = useState(false);
    const [statusAction, setStatusAction] = useState<StatusAction | null>(null);
    const [response, setResponse] = useState('');
    const [feedback, setFeedback] = useState<Feedback>(null);

    const availableStatusActions = useMemo<StatusAction[]>(() => {
        if (changeRequest.can.update_status) {
            if (changeRequest.status === 'pending') {
                return ['accepted', 'rejected'];
            }

            if (changeRequest.status === 'accepted') {
                return ['completed', 'cancelled'];
            }
        }

        if (changeRequest.can.cancel && changeRequest.status === 'pending') {
            return ['cancelled'];
        }

        return [];
    }, [changeRequest.can, changeRequest.status]);

    const actionLabels: Record<StatusAction, string> = {
        accepted: t('changeRequests.accept'),
        rejected: t('changeRequests.reject'),
        completed: t('changeRequests.complete'),
        cancelled: t('changeRequests.cancelRequest'),
    };

    function submitComment(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setFeedback(null);

        if (body.trim() === '') {
            setFeedback({
                type: 'error',
                message: t('changeRequests.commentRequired'),
            });

            return;
        }

        router.post(
            changeRequestCommentsUrl(query.id, changeRequest.id),
            {
                body: body.trim(),
                mentioned_user_ids: mentionedUserIds,
            },
            {
                preserveScroll: true,
                onStart: () => setCommentPending(true),
                onError: (errors) =>
                    setFeedback({
                        type: 'error',
                        message: validationMessage(
                            errors,
                            t('changeRequests.commentError'),
                        ),
                    }),
                onSuccess: () => {
                    setBody('');
                    setMentionedUserIds([]);
                    setFeedback({
                        type: 'success',
                        message: t('changeRequests.commentSuccess'),
                    });
                },
                onFinish: () => setCommentPending(false),
            },
        );
    }

    function updateStatus() {
        if (statusAction === null) {
            return;
        }

        setFeedback(null);
        router.patch(
            changeRequestShowUrl(query.id, changeRequest.id),
            {
                status: statusAction,
                response: response.trim() || undefined,
            },
            {
                preserveScroll: true,
                onStart: () => setStatusPending(true),
                onError: (errors) =>
                    setFeedback({
                        type: 'error',
                        message: validationMessage(
                            errors,
                            t('changeRequests.statusError'),
                        ),
                    }),
                onSuccess: () => {
                    setFeedback({
                        type: 'success',
                        message: t('changeRequests.statusSuccess'),
                    });
                    setStatusAction(null);
                    setResponse('');
                },
                onFinish: () => setStatusPending(false),
            },
        );
    }

    function loadCommentPage(page: number) {
        if (commentPage === null) {
            return;
        }

        setNavigationPending(true);
        router.get(
            changeRequestShowUrl(query.id, changeRequest.id),
            { comment_page: page, per_page: commentPage.meta.per_page },
            {
                only: ['comments'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onFinish: () => setNavigationPending(false),
            },
        );
    }

    function actionIcon(action: StatusAction) {
        if (action === 'accepted') {
            return <Check aria-hidden="true" />;
        }

        if (action === 'completed') {
            return <CheckCheck aria-hidden="true" />;
        }

        if (action === 'rejected') {
            return <X aria-hidden="true" />;
        }

        return <Ban aria-hidden="true" />;
    }

    return (
        <>
            <Head title={changeRequest.title} />

            <div className="px-6 py-6">
                <Heading
                    title={changeRequest.title}
                    description={t('changeRequests.threadDescription', {
                        query: query.name,
                    })}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={changeRequestsIndexUrl(query.id)}>
                                    {t('changeRequests.backToList')}
                                </Link>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <Link href={queries.show(query.id)}>
                                    {t('changeRequests.backToQuery')}
                                </Link>
                            </Button>
                        </div>
                    }
                />

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
                            <CircleHelp aria-hidden="true" />
                            <AlertTitle>
                                {feedback.type === 'error'
                                    ? t('changeRequests.errorTitle')
                                    : t('changeRequests.successTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {feedback.message}
                            </AlertDescription>
                        </Alert>
                    )}

                    <Card>
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <CardTitle>
                                            {t('changeRequests.requestSummary')}
                                        </CardTitle>
                                        <ChangeRequestStatusBadge
                                            status={changeRequest.status}
                                        />
                                    </div>
                                    <CardDescription>
                                        {t('changeRequests.requestedBy', {
                                            name:
                                                changeRequest.requested_by
                                                    ?.name ??
                                                t('changeRequests.deletedUser'),
                                            date: formatDate(
                                                changeRequest.created_at,
                                                {
                                                    dateStyle: 'medium',
                                                    timeStyle: 'short',
                                                },
                                            ),
                                        })}
                                    </CardDescription>
                                    {changeRequest.status_changed_at && (
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'changeRequests.statusChangedBy',
                                                {
                                                    name:
                                                        changeRequest
                                                            .status_changed_by
                                                            ?.name ??
                                                        t(
                                                            'changeRequests.deletedUser',
                                                        ),
                                                    date: formatDate(
                                                        changeRequest.status_changed_at,
                                                        {
                                                            dateStyle: 'medium',
                                                            timeStyle: 'short',
                                                        },
                                                    ),
                                                },
                                            )}
                                        </p>
                                    )}
                                </div>
                                {availableStatusActions.length > 0 && (
                                    <div className="flex flex-wrap gap-2">
                                        {availableStatusActions.map(
                                            (action) => (
                                                <Button
                                                    key={action}
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        action === 'rejected' ||
                                                        action === 'cancelled'
                                                            ? 'outline'
                                                            : 'default'
                                                    }
                                                    onClick={() => {
                                                        setResponse('');
                                                        setStatusAction(action);
                                                    }}
                                                >
                                                    {actionIcon(action)}
                                                    {actionLabels[action]}
                                                </Button>
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>
                        </CardHeader>
                    </Card>

                    <Card aria-busy={navigationPending}>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <MessageCircle aria-hidden="true" />
                                {t('changeRequests.threadTitle')}
                            </CardTitle>
                            <CardDescription>
                                {t('changeRequests.threadHelp')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {commentItems.length === 0 ? (
                                <div className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                                    {t('changeRequests.noComments')}
                                </div>
                            ) : (
                                <ol className="space-y-4">
                                    {commentItems.map((comment) => (
                                        <li
                                            key={comment.id}
                                            className="rounded-lg border p-4"
                                        >
                                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                                <span className="flex items-center gap-2 font-medium">
                                                    <UserRound
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {comment.author?.name ??
                                                        t(
                                                            'changeRequests.deletedUser',
                                                        )}
                                                </span>
                                                <time
                                                    dateTime={
                                                        comment.created_at
                                                    }
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    {formatDate(
                                                        comment.created_at,
                                                        {
                                                            dateStyle: 'medium',
                                                            timeStyle: 'short',
                                                        },
                                                    )}
                                                </time>
                                            </div>
                                            <p className="text-sm leading-relaxed break-words whitespace-pre-wrap">
                                                {comment.body}
                                            </p>
                                            {comment.mentions.length > 0 && (
                                                <div
                                                    className="mt-3 flex flex-wrap items-center gap-2"
                                                    aria-label={t(
                                                        'changeRequests.mentionedPeople',
                                                    )}
                                                >
                                                    {comment.mentions.map(
                                                        (mention) => (
                                                            <Badge
                                                                key={mention.id}
                                                                variant="secondary"
                                                            >
                                                                @{mention.name}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}

                            {commentPage !== null && (
                                <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                    <span>
                                        {t('changeRequests.commentCount', {
                                            count: commentPage.meta.total,
                                        })}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                loadCommentPage(
                                                    commentPage.meta
                                                        .current_page - 1,
                                                )
                                            }
                                            disabled={
                                                navigationPending ||
                                                commentPage.meta.current_page <=
                                                    1
                                            }
                                            aria-label={t('queries.previous')}
                                        >
                                            <ChevronLeft aria-hidden="true" />
                                        </Button>
                                        <span>
                                            {t('queries.page')}{' '}
                                            {commentPage.meta.current_page} /{' '}
                                            {commentPage.meta.last_page}
                                        </span>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                loadCommentPage(
                                                    commentPage.meta
                                                        .current_page + 1,
                                                )
                                            }
                                            disabled={
                                                navigationPending ||
                                                commentPage.meta.current_page >=
                                                    commentPage.meta.last_page
                                            }
                                            aria-label={t('queries.next')}
                                        >
                                            <ChevronRight aria-hidden="true" />
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {changeRequest.can.comment && (
                                <form
                                    onSubmit={submitComment}
                                    className="space-y-4 rounded-lg bg-muted/40 p-4"
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor="change-request-comment">
                                            {t('changeRequests.commentLabel')}
                                        </Label>
                                        <Textarea
                                            id="change-request-comment"
                                            value={body}
                                            onChange={(event) =>
                                                setBody(event.target.value)
                                            }
                                            maxLength={5000}
                                            rows={6}
                                            required
                                            disabled={commentPending}
                                            placeholder={t(
                                                'changeRequests.commentPlaceholder',
                                            )}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'changeRequests.characterCount',
                                                {
                                                    count: body.length,
                                                    max: 5000,
                                                },
                                            )}
                                        </p>
                                    </div>

                                    <MentionSelector
                                        candidates={candidates}
                                        selected={mentionedUserIds}
                                        onChange={setMentionedUserIds}
                                        disabled={commentPending}
                                    />

                                    <div className="flex justify-end">
                                        <Button
                                            type="submit"
                                            disabled={
                                                commentPending ||
                                                body.trim() === ''
                                            }
                                        >
                                            {commentPending ? (
                                                <Spinner
                                                    aria-label={t(
                                                        'common.loading',
                                                    )}
                                                />
                                            ) : (
                                                <Send aria-hidden="true" />
                                            )}
                                            {t('changeRequests.sendComment')}
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog
                open={statusAction !== null}
                onOpenChange={(open) => {
                    if (!open && !statusPending) {
                        setStatusAction(null);
                        setResponse('');
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {statusAction
                                ? t('changeRequests.statusConfirmTitle', {
                                      action: actionLabels[statusAction],
                                  })
                                : t('changeRequests.updateStatus')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('changeRequests.statusConfirmDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="change-request-response">
                            {t('changeRequests.responseLabel')}
                        </Label>
                        <Textarea
                            id="change-request-response"
                            value={response}
                            onChange={(event) =>
                                setResponse(event.target.value)
                            }
                            maxLength={5000}
                            rows={5}
                            disabled={statusPending}
                            placeholder={t(
                                'changeRequests.responsePlaceholder',
                            )}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStatusAction(null)}
                            disabled={statusPending}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant={
                                statusAction === 'rejected' ||
                                statusAction === 'cancelled'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={updateStatus}
                            disabled={statusPending}
                        >
                            {statusPending && (
                                <Spinner aria-label={t('common.loading')} />
                            )}
                            {statusAction
                                ? actionLabels[statusAction]
                                : t('changeRequests.updateStatus')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

QueryChangeRequestShow.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Collaboration', href: '#' },
    ],
};
