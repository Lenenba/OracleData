import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    FileClock,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { TemplateGovernanceStatusBadge } from '@/components/queries/template-governance-status-badge';
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
import { useI18n } from '@/i18n/i18n-context';
import type {
    LaravelPaginator,
    QueryTemplateGovernanceStatus,
    QueryTemplateGovernanceSummary,
} from '@/types/query-template-governance';

type IndexProps = {
    templates: LaravelPaginator<QueryTemplateGovernanceSummary>;
    filters: {
        search: string;
        status: QueryTemplateGovernanceStatus | null;
    };
    statuses: QueryTemplateGovernanceStatus[];
};

const governanceIndexUrl = '/settings/query-templates';
const governanceShowUrl = (slug: string) =>
    `/settings/query-templates/${encodeURIComponent(slug)}`;

export default function QueryTemplateGovernanceIndex({
    templates,
    filters,
    statuses,
}: IndexProps) {
    const { t, formatDate } = useI18n();
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState<QueryTemplateGovernanceStatus | 'all'>(
        filters.status ?? 'all',
    );
    const [pending, setPending] = useState(false);

    const statusLabels: Record<QueryTemplateGovernanceStatus, string> = {
        draft: t('templateGovernance.statusDraft'),
        review: t('templateGovernance.statusReview'),
        published: t('templateGovernance.statusPublished'),
        archived: t('templateGovernance.statusArchived'),
    };

    function load(page: number, nextStatus = status, nextSearch = search) {
        setPending(true);
        router.get(
            governanceIndexUrl,
            {
                search: nextSearch.trim() || undefined,
                status: nextStatus === 'all' ? undefined : nextStatus,
                page,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setPending(false),
            },
        );
    }

    function submitFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        load(1);
    }

    function resetFilters() {
        setSearch('');
        setStatus('all');
        load(1, 'all', '');
    }

    return (
        <>
            <Head title={t('templateGovernance.pageTitle')} />
            <h1 className="sr-only">{t('templateGovernance.pageTitle')}</h1>

            <div className="space-y-5">
                <Heading
                    title={t('templateGovernance.title')}
                    description={t('templateGovernance.description')}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('templateGovernance.filtersTitle')}
                        </CardTitle>
                        <CardDescription>
                            {t('templateGovernance.filtersDescription')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submitFilters}
                            className="grid gap-4 lg:grid-cols-[minmax(16rem,1fr)_15rem_auto] lg:items-end"
                            role="search"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="governance-template-search">
                                    {t('templateGovernance.searchLabel')}
                                </Label>
                                <div className="relative">
                                    <Search
                                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        id="governance-template-search"
                                        type="search"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        maxLength={100}
                                        placeholder={t(
                                            'templateGovernance.searchPlaceholder',
                                        )}
                                        className="pl-9"
                                        disabled={pending}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="governance-template-status">
                                    {t('templateGovernance.statusFilter')}
                                </Label>
                                <Select
                                    value={status}
                                    onValueChange={(value) =>
                                        setStatus(
                                            value as
                                                | QueryTemplateGovernanceStatus
                                                | 'all',
                                        )
                                    }
                                    disabled={pending}
                                >
                                    <SelectTrigger id="governance-template-status">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            {t(
                                                'templateGovernance.allStatuses',
                                            )}
                                        </SelectItem>
                                        {statuses.map((item) => (
                                            <SelectItem key={item} value={item}>
                                                {statusLabels[item]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={pending}>
                                    {t('templateGovernance.applyFilters')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={resetFilters}
                                    disabled={pending}
                                >
                                    {t('templateGovernance.resetFilters')}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card aria-busy={pending}>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <CardTitle>
                                    {t('templateGovernance.catalogTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {t('templateGovernance.resultCount', {
                                        count: templates.total,
                                    })}
                                </CardDescription>
                            </div>
                            <Badge variant="secondary">
                                <ShieldCheck aria-hidden="true" />
                                {t('templateGovernance.superAdminOnly')}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {templates.data.length === 0 ? (
                            <div className="rounded-lg border border-dashed px-4 py-12 text-center">
                                <FileClock
                                    className="mx-auto mb-3 size-9 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <p className="font-medium">
                                    {t('templateGovernance.emptyTitle')}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {t('templateGovernance.emptyDescription')}
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {templates.data.map((template) => (
                                    <li
                                        key={template.slug}
                                        className="grid gap-4 p-4 xl:grid-cols-[minmax(15rem,1.4fr)_minmax(12rem,1fr)_minmax(12rem,1fr)_auto] xl:items-center"
                                    >
                                        <div className="min-w-0 space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <TemplateGovernanceStatusBadge
                                                    status={
                                                        template.governance_status
                                                    }
                                                />
                                                {!template.is_active && (
                                                    <Badge variant="outline">
                                                        {t(
                                                            'templateGovernance.inactive',
                                                        )}
                                                    </Badge>
                                                )}
                                                {template.certification
                                                    ?.is_effective && (
                                                    <Badge>
                                                        <ShieldCheck aria-hidden="true" />
                                                        {t(
                                                            'templateGovernance.certifiedBadge',
                                                        )}
                                                    </Badge>
                                                )}
                                                {template.is_review_overdue && (
                                                    <Badge variant="destructive">
                                                        <AlertTriangle aria-hidden="true" />
                                                        {t(
                                                            'templateGovernance.reviewOverdue',
                                                        )}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div>
                                                <Link
                                                    href={governanceShowUrl(
                                                        template.slug,
                                                    )}
                                                    className="font-semibold underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                >
                                                    {template.name}
                                                </Link>
                                                <p className="truncate font-mono text-xs text-muted-foreground">
                                                    {template.slug}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="space-y-1 text-sm">
                                            <p className="font-medium">
                                                {t(
                                                    'templateGovernance.versionState',
                                                )}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {template.open_version
                                                    ? t(
                                                          'templateGovernance.openVersion',
                                                          {
                                                              version:
                                                                  template
                                                                      .open_version
                                                                      .version_number,
                                                              status: statusLabels[
                                                                  template
                                                                      .open_version
                                                                      .status
                                                              ],
                                                          },
                                                      )
                                                    : template.published_version
                                                      ? t(
                                                            'templateGovernance.publishedVersion',
                                                            {
                                                                version:
                                                                    template
                                                                        .published_version
                                                                        .version_number,
                                                            },
                                                        )
                                                      : t(
                                                            'templateGovernance.noVersion',
                                                        )}
                                            </p>
                                        </div>

                                        <div className="space-y-1 text-sm">
                                            <p className="font-medium">
                                                {template.business_owner
                                                    ?.name ??
                                                    t(
                                                        'templateGovernance.noBusinessOwner',
                                                    )}
                                            </p>
                                            <p
                                                className={
                                                    template.is_review_overdue
                                                        ? 'font-medium text-destructive'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {template.review_due_at
                                                    ? t(
                                                          'templateGovernance.reviewDue',
                                                          {
                                                              date: formatDate(
                                                                  template.review_due_at,
                                                                  {
                                                                      dateStyle:
                                                                          'medium',
                                                                  },
                                                              ),
                                                          },
                                                      )
                                                    : t(
                                                          'templateGovernance.noReviewDate',
                                                      )}
                                            </p>
                                        </div>

                                        <Button asChild size="sm">
                                            <Link
                                                href={governanceShowUrl(
                                                    template.slug,
                                                )}
                                            >
                                                {t('templateGovernance.manage')}
                                            </Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                            <span>
                                {t('templateGovernance.resultCount', {
                                    count: templates.total,
                                })}
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        load(templates.current_page - 1)
                                    }
                                    disabled={
                                        pending || templates.current_page <= 1
                                    }
                                    aria-label={t('queries.previous')}
                                >
                                    <ChevronLeft aria-hidden="true" />
                                </Button>
                                <span>
                                    {t('queries.page')} {templates.current_page}{' '}
                                    / {templates.last_page}
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        load(templates.current_page + 1)
                                    }
                                    disabled={
                                        pending ||
                                        templates.current_page >=
                                            templates.last_page
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
        </>
    );
}

QueryTemplateGovernanceIndex.layout = {
    breadcrumbs: [
        {
            title: 'Gouvernance des modèles',
            href: governanceIndexUrl,
        },
    ],
};
