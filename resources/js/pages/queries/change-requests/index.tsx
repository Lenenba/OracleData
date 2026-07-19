import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    MessageSquareText,
    Plus,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import Heading from '@/components/heading';
import { ChangeRequestStatusBadge } from '@/components/queries/change-request-status-badge';
import { MentionSelector } from '@/components/queries/mention-selector';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import queries from '@/routes/queries';
import type {
    PaginatedQueryChangeRequests,
    QueryChangeRequestQuery,
    QueryChangeRequestStatus,
    QueryChangeRequestUser,
} from '@/types/change-requests';

type StatusFilter = 'all' | QueryChangeRequestStatus;

type IndexProps = {
    query: QueryChangeRequestQuery;
    changeRequests: PaginatedQueryChangeRequests;
    statusFilter: StatusFilter;
    mentionCandidates:
        | QueryChangeRequestUser[]
        | { data: QueryChangeRequestUser[] };
};

type Feedback = { type: 'success' | 'error'; message: string } | null;

// These local helpers match the named Wayfinder routes. They keep this page
// type-checkable while the backend route generator is running in parallel.
const changeRequestsIndexUrl = (queryId: number) =>
    `/queries/${queryId}/change-requests`;
const changeRequestShowUrl = (queryId: number, requestId: number) =>
    `/queries/${queryId}/change-requests/${requestId}`;

function validationMessage(errors: Record<string, string>, fallback: string) {
    return Object.values(errors)[0] ?? fallback;
}

export default function QueryChangeRequestsIndex({
    query,
    changeRequests,
    statusFilter,
    mentionCandidates,
}: IndexProps) {
    const { t, formatDate } = useI18n();
    const candidates = Array.isArray(mentionCandidates)
        ? mentionCandidates
        : mentionCandidates.data;
    const [createOpen, setCreateOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [message, setMessage] = useState('');
    const [mentionedUserIds, setMentionedUserIds] = useState<number[]>([]);
    const [pending, setPending] = useState(false);
    const [navigationPending, setNavigationPending] = useState(false);
    const [feedback, setFeedback] = useState<Feedback>(null);

    const statusLabels: Record<StatusFilter, string> = {
        all: t('changeRequests.filterAll'),
        pending: t('changeRequests.statusPending'),
        accepted: t('changeRequests.statusAccepted'),
        rejected: t('changeRequests.statusRejected'),
        completed: t('changeRequests.statusCompleted'),
        cancelled: t('changeRequests.statusCancelled'),
    };

    function load(page: number, status: StatusFilter = statusFilter) {
        setNavigationPending(true);
        router.get(
            changeRequestsIndexUrl(query.id),
            {
                status: status === 'all' ? undefined : status,
                page,
                per_page: changeRequests.meta.per_page,
            },
            {
                only: ['changeRequests', 'statusFilter', 'mentionCandidates'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onFinish: () => setNavigationPending(false),
            },
        );
    }

    function resetForm() {
        setTitle('');
        setMessage('');
        setMentionedUserIds([]);
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setFeedback(null);

        if (title.trim() === '' || message.trim() === '') {
            setFeedback({
                type: 'error',
                message: t('changeRequests.requiredFields'),
            });

            return;
        }

        router.post(
            changeRequestsIndexUrl(query.id),
            {
                title: title.trim(),
                message: message.trim(),
                mentioned_user_ids: mentionedUserIds,
            },
            {
                preserveScroll: true,
                onStart: () => setPending(true),
                onError: (errors) =>
                    setFeedback({
                        type: 'error',
                        message: validationMessage(
                            errors,
                            t('changeRequests.createError'),
                        ),
                    }),
                onSuccess: () => {
                    setFeedback({
                        type: 'success',
                        message: t('changeRequests.createSuccess'),
                    });
                    setCreateOpen(false);
                    resetForm();
                },
                onFinish: () => setPending(false),
            },
        );
    }

    return (
        <>
            <Head title={t('changeRequests.pageTitle')} />

            <div className="px-6 py-6">
                <Heading
                    title={t('changeRequests.title')}
                    description={t('changeRequests.description', {
                        query: query.name,
                    })}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={queries.show(query.id)}>
                                    {t('changeRequests.backToQuery')}
                                </Link>
                            </Button>
                            {query.can_create_change_request && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => {
                                        setFeedback(null);
                                        setCreateOpen(true);
                                    }}
                                >
                                    <Plus aria-hidden="true" />
                                    {t('changeRequests.create')}
                                </Button>
                            )}
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

                    <Card aria-busy={navigationPending}>
                        <CardHeader>
                            <div className="flex flex-wrap items-end justify-between gap-4">
                                <div className="space-y-1.5">
                                    <CardTitle>
                                        {t('changeRequests.listTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t('changeRequests.listDescription')}
                                    </CardDescription>
                                </div>
                                <div className="grid min-w-48 gap-2">
                                    <Label htmlFor="change-request-status-filter">
                                        {t('changeRequests.filterLabel')}
                                    </Label>
                                    <Select
                                        value={statusFilter}
                                        onValueChange={(value) =>
                                            load(1, value as StatusFilter)
                                        }
                                        disabled={navigationPending}
                                    >
                                        <SelectTrigger id="change-request-status-filter">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(statusLabels).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {changeRequests.data.length === 0 ? (
                                <div className="rounded-lg border border-dashed px-4 py-10 text-center">
                                    <MessageSquareText
                                        className="mx-auto mb-3 size-8 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <p className="font-medium">
                                        {t('changeRequests.emptyTitle')}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {statusFilter === 'all'
                                            ? t(
                                                  'changeRequests.emptyDescription',
                                              )
                                            : t(
                                                  'changeRequests.emptyFilteredDescription',
                                              )}
                                    </p>
                                </div>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {changeRequests.data.map((request) => (
                                        <li
                                            key={request.id}
                                            className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0 space-y-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <ChangeRequestStatusBadge
                                                        status={request.status}
                                                    />
                                                    <Link
                                                        href={changeRequestShowUrl(
                                                            query.id,
                                                            request.id,
                                                        )}
                                                        className="font-medium underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    >
                                                        {request.title}
                                                    </Link>
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {t(
                                                        'changeRequests.requestedBy',
                                                        {
                                                            name:
                                                                request
                                                                    .requested_by
                                                                    ?.name ??
                                                                t(
                                                                    'changeRequests.deletedUser',
                                                                ),
                                                            date: formatDate(
                                                                request.created_at,
                                                                {
                                                                    dateStyle:
                                                                        'medium',
                                                                    timeStyle:
                                                                        'short',
                                                                },
                                                            ),
                                                        },
                                                    )}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <span className="text-sm text-muted-foreground">
                                                    {t(
                                                        'changeRequests.commentCount',
                                                        {
                                                            count: request.comment_count,
                                                        },
                                                    )}
                                                </span>
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={changeRequestShowUrl(
                                                            query.id,
                                                            request.id,
                                                        )}
                                                    >
                                                        {t(
                                                            'changeRequests.openThread',
                                                        )}
                                                    </Link>
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                <span>
                                    {t('changeRequests.resultCount', {
                                        count: changeRequests.meta.total,
                                    })}
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            load(
                                                changeRequests.meta
                                                    .current_page - 1,
                                            )
                                        }
                                        disabled={
                                            navigationPending ||
                                            changeRequests.meta.current_page <=
                                                1
                                        }
                                        aria-label={t('queries.previous')}
                                    >
                                        <ChevronLeft aria-hidden="true" />
                                    </Button>
                                    <span>
                                        {t('queries.page')}{' '}
                                        {changeRequests.meta.current_page} /{' '}
                                        {changeRequests.meta.last_page}
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            load(
                                                changeRequests.meta
                                                    .current_page + 1,
                                            )
                                        }
                                        disabled={
                                            navigationPending ||
                                            changeRequests.meta.current_page >=
                                                changeRequests.meta.last_page
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
                open={createOpen}
                onOpenChange={(open) => {
                    if (!pending) {
                        setCreateOpen(open);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('changeRequests.createTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('changeRequests.createDescription', {
                                query: query.name,
                            })}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="change-request-title">
                                {t('changeRequests.titleLabel')}
                            </Label>
                            <Input
                                id="change-request-title"
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                maxLength={160}
                                required
                                autoFocus
                                disabled={pending}
                                placeholder={t(
                                    'changeRequests.titlePlaceholder',
                                )}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="change-request-message">
                                {t('changeRequests.messageLabel')}
                            </Label>
                            <Textarea
                                id="change-request-message"
                                value={message}
                                onChange={(event) =>
                                    setMessage(event.target.value)
                                }
                                maxLength={5000}
                                required
                                rows={7}
                                disabled={pending}
                                placeholder={t(
                                    'changeRequests.messagePlaceholder',
                                )}
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('changeRequests.characterCount', {
                                    count: message.length,
                                    max: 5000,
                                })}
                            </p>
                        </div>

                        <MentionSelector
                            candidates={candidates}
                            selected={mentionedUserIds}
                            onChange={setMentionedUserIds}
                            disabled={pending}
                        />

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateOpen(false)}
                                disabled={pending}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" disabled={pending}>
                                {pending && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                {t('changeRequests.submit')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

QueryChangeRequestsIndex.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Collaboration', href: '#' },
    ],
};
