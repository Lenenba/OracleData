import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Copy,
    Eye,
    MessageSquareText,
    Pencil,
    Share2,
    Sparkles,
    X,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { ChainedQueryPanel } from '@/components/queries/chained-query-panel';
import { ParameterDefinitionEditor } from '@/components/queries/parameter-definition-editor';
import { QueryAccessLevelBadge } from '@/components/queries/query-access-level-badge';
import { QueryExportButton } from '@/components/queries/query-export-button';
import { QueryResultView } from '@/components/queries/query-result';
import type { QueryResult } from '@/components/queries/query-result';
import { RuntimeParameterForm } from '@/components/queries/runtime-parameter-form';
import { TrendPanel } from '@/components/queries/trend-panel';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useAgentRun } from '@/hooks/use-agent-run';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';
import type {
    QueryAccessLevel,
    QueryCapabilities,
} from '@/types/query-sharing';

type ParameterDefinition = {
    key: string;
    type: 'string' | 'integer' | 'number' | 'date' | 'boolean' | 'select';
    label: string;
    required: boolean;
    default?: string | number | boolean | null;
    description?: string | null;
    min?: number;
    max?: number;
    step?: number;
    options?: Array<{ value: string; label: string }>;
    binding?: {
        kind: 'filter' | 'parameter';
        field?: string;
        operator?: string;
        key?: string;
    };
};

type QueryDetail = {
    id: number;
    name: string;
    description: string | null;
    resource_path: string | null;
    tenant_key: string | null;
    mode: 'single' | 'agent';
    parameters: Record<string, unknown>;
    parameter_definitions: ParameterDefinition[];
    access_level: QueryAccessLevel;
    category: { slug: string; name: string; color: string | null } | null;
    tags: Array<{ slug: string; name: string }>;
    can: QueryCapabilities;
};

type ShowProps = {
    query: QueryDetail;
    tenants: Record<string, string>;
    defaultTenant: string;
    /** Queries accessible to the current user, for the chain secondary picker. */
    accessibleQueries: Array<{ id: number; name: string }>;
};

export default function ShowQuery({
    query,
    tenants,
    defaultTenant,
    accessibleQueries,
}: ShowProps) {
    const { t } = useI18n();
    const tenantKeys = Object.keys(tenants);
    const [tenant, setTenant] = useState(
        query.tenant_key && tenantKeys.includes(query.tenant_key)
            ? query.tenant_key
            : tenantKeys.includes(defaultTenant)
              ? defaultTenant
              : (tenantKeys[0] ?? ''),
    );
    const [status, setStatus] = useState<'idle' | 'loading' | 'done'>('idle');
    const [result, setResult] = useState<QueryResult | null>(null);
    const [fetchError, setFetchError] = useState<string | null>(null);
    // Lot 10E — offset for server-side pagination (advances Oracle cursor).
    const [pageOffset, setPageOffset] = useState(0);

    // Lot 10A — runtime parameter values filled by the user before execution.
    const [paramValues, setParamValues] = useState<
        Record<string, string | number | boolean | null>
    >(() => {
        const defaults: Record<string, string | number | boolean | null> = {};

        for (const def of query.parameter_definitions) {
            if (def.default !== undefined && def.default !== null) {
                defaults[def.key] = def.default;
            }
        }

        return defaults;
    });

    const isAgent = query.mode === 'agent';
    const agent = useAgentRun(query.id);
    const agentRun = agent.run;
    const hasParams = query.parameter_definitions.length > 0;
    // Lot 10E — keep a boolean so TypeScript doesn't narrow 'status' away inside done blocks.
    const isRunning = status === 'loading';

    const tenantLabel = tenants[result?.tenant ?? tenant] ?? tenant;
    const changeRequestsUrl = `/queries/${query.id}/change-requests`;

    function cloneQuery() {
        router.post(queries.clone(query.id));
    }

    function execute() {
        if (!query.can.execute) {
            return;
        }

        if (isAgent) {
            void agent.start(tenant);

            return;
        }

        // Lot 10E — reset offset when the user triggers a fresh run.
        setPageOffset(0);
        void run(0);
    }

    async function run(offset = pageOffset) {
        if (!query.can.execute) {
            return;
        }

        setStatus('loading');
        setFetchError(null);

        if (offset === 0) {
            setResult(null);
        }

        try {
            const body: Record<string, unknown> = { tenant };

            if (hasParams) {
                body.parameter_values = paramValues;
            }

            if (offset > 0) {
                body.offset = offset;
            }

            const response = await fetch(queries.run.url(query.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const data = (await response
                .json()
                .catch(() => null)) as QueryResult | null;

            if (!response.ok || data === null) {
                setFetchError(
                    (data as { message?: string } | null)?.message ??
                        t('queries.runError'),
                );
                setStatus('done');

                return;
            }

            setResult(data);
            setStatus('done');
        } catch {
            setFetchError(t('queries.networkError'));
            setStatus('done');
        }
    }

    return (
        <>
            <Head title={query.name} />

            <div className="p-5">
                <Heading
                    title={query.name}
                    description={query.description ?? undefined}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={changeRequestsUrl}>
                                    <MessageSquareText className="size-3.5" />
                                    {t('changeRequests.openCollaboration')}
                                </Link>
                            </Button>
                            {query.can.clone && (
                                <Button
                                    type="button"
                                    variant={
                                        query.can.update ? 'outline' : 'default'
                                    }
                                    size="sm"
                                    onClick={cloneQuery}
                                >
                                    <Copy className="size-3.5" />
                                    {t('queries.clone')}
                                </Button>
                            )}
                            {query.can.update && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={queries.edit(query.id)}>
                                        <Pencil className="size-3.5" />
                                        {t('queries.edit')}
                                    </Link>
                                </Button>
                            )}
                            {query.can.manage_sharing && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={queries.shares.index(query.id)}>
                                        <Share2 className="size-3.5" />
                                        {t('queries.manageSharing')}
                                    </Link>
                                </Button>
                            )}
                            {query.can.update && (
                                <ParameterDefinitionEditor
                                    queryId={query.id}
                                    definitions={query.parameter_definitions}
                                />
                            )}
                        </div>
                    }
                />

                <div className="space-y-5">
                    <div className="grid items-start gap-5 xl:grid-cols-2">
                        <section className="card">
                            <div className="card-header">
                                <h2 className="card-title">
                                    {t('queries.information')}
                                </h2>
                                <QueryAccessLevelBadge
                                    accessLevel={query.access_level}
                                />
                            </div>

                            <div className="card-body space-y-4">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="rounded border border-border bg-muted/25 px-4 py-3">
                                        <span className="block text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                            {t('queries.modeLabel')}
                                        </span>
                                        <div className="mt-2">
                                            <Badge variant="outline">
                                                {query.mode === 'agent'
                                                    ? t('queries.analysis')
                                                    : t('queries.queryMode')}
                                            </Badge>
                                        </div>
                                    </div>

                                    <div className="rounded border border-border bg-muted/25 px-4 py-3">
                                        <span className="block text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                            {t('queries.environment')}
                                        </span>
                                        <p className="mt-2 truncate text-sm font-semibold text-foreground">
                                            {tenants[query.tenant_key ?? ''] ??
                                                query.tenant_key ??
                                                t('queries.noEnvironment')}
                                        </p>
                                    </div>
                                </div>

                                {query.resource_path && (
                                    <div className="rounded border border-border px-4 py-3">
                                        <span className="block text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                            {t('queries.oracleResource')}
                                        </span>
                                        <code className="mt-2 block text-xs break-all text-primary">
                                            {query.resource_path}
                                        </code>
                                    </div>
                                )}

                                {(query.category || query.tags.length > 0) && (
                                    <div className="flex flex-wrap items-center gap-2 border-t border-dashed border-border pt-4">
                                        {query.category && (
                                            <Badge
                                                variant="outline"
                                                style={
                                                    query.category.color
                                                        ? {
                                                              borderColor:
                                                                  query.category
                                                                      .color,
                                                              color: query
                                                                  .category
                                                                  .color,
                                                          }
                                                        : undefined
                                                }
                                            >
                                                {query.category.name}
                                            </Badge>
                                        )}
                                        {query.tags.map((tag) => (
                                            <Badge
                                                key={tag.slug}
                                                variant="secondary"
                                            >
                                                {tag.name}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </section>

                        {query.can.execute ? (
                            <section className="card">
                                <div className="card-header">
                                    <h2 className="card-title">
                                        {t('queries.executionPanel')}
                                    </h2>
                                    <Badge variant="secondary">
                                        {tenantLabel ||
                                            t('queries.noEnvironment')}
                                    </Badge>
                                </div>

                                <div className="card-body space-y-5">
                                    {/* Lot 10A — runtime parameter form */}
                                    {hasParams && !isAgent && (
                                        <RuntimeParameterForm
                                            definitions={
                                                query.parameter_definitions
                                            }
                                            values={paramValues}
                                            disabled={status === 'loading'}
                                            onChange={(key, value) =>
                                                setParamValues((prev) => ({
                                                    ...prev,
                                                    [key]: value,
                                                }))
                                            }
                                        />
                                    )}

                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                        <div className="grid min-w-0 flex-1 gap-2">
                                            <Label htmlFor="tenant">
                                                {t('queries.environment')}
                                            </Label>
                                            <Select
                                                value={tenant}
                                                onValueChange={setTenant}
                                            >
                                                <SelectTrigger
                                                    id="tenant"
                                                    className="w-full"
                                                >
                                                    <SelectValue
                                                        placeholder={t(
                                                            'queries.chooseEnvironment',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {tenantKeys.map((key) => (
                                                        <SelectItem
                                                            key={key}
                                                            value={key}
                                                        >
                                                            {tenants[key]}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <Button
                                            className="sm:shrink-0"
                                            onClick={execute}
                                            disabled={
                                                (isAgent
                                                    ? agent.isActive
                                                    : status === 'loading') ||
                                                tenant === ''
                                            }
                                        >
                                            {(
                                                isAgent
                                                    ? agent.isActive
                                                    : status === 'loading'
                                            ) ? (
                                                <Spinner />
                                            ) : isAgent ? (
                                                <Sparkles className="size-4" />
                                            ) : null}
                                            {isAgent
                                                ? t('queries.runAnalysis')
                                                : t('queries.run')}
                                        </Button>

                                        {isAgent && agent.isActive && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="sm:shrink-0"
                                                onClick={() =>
                                                    void agent.cancel()
                                                }
                                            >
                                                <X className="size-4" />
                                                {t('queries.cancelAnalysis')}
                                            </Button>
                                        )}
                                    </div>

                                    {!isAgent && (
                                        <div className="flex justify-end border-t border-dashed border-border pt-4">
                                            <QueryExportButton
                                                queryId={query.id}
                                                tenant={tenant}
                                                disabled={tenant === ''}
                                            />
                                        </div>
                                    )}
                                </div>
                            </section>
                        ) : (
                            <Alert>
                                <Eye />
                                <AlertTitle>
                                    {t('sharing.viewOnlyTitle')}
                                </AlertTitle>
                                <AlertDescription>
                                    {t('sharing.viewOnlyDescription')}
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>

                    {!isAgent && status === 'loading' && (
                        <div className="space-y-2">
                            <Skeleton className="h-9 w-full" />
                            <Skeleton className="h-9 w-full" />
                            <Skeleton className="h-9 w-full" />
                        </div>
                    )}

                    {!isAgent && status === 'done' && fetchError && (
                        <QueryResultView
                            result={
                                {
                                    mode: query.mode,
                                    tenant,
                                    resource: null,
                                    parameters: null,
                                    columns: null,
                                    analysis: null,
                                    items: [],
                                    count: 0,
                                    hasMore: false,
                                    oracleCalls: [],
                                    clarification: null,
                                    error: fetchError,
                                } satisfies QueryResult
                            }
                            tenantLabel={tenantLabel}
                        />
                    )}

                    {!isAgent && status === 'done' && !fetchError && result && (
                        <>
                            <QueryResultView
                                result={result}
                                tenantLabel={tenantLabel}
                            />
                            {/* Lot 10E — server-side pagination controls */}
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <span className="text-muted-foreground">
                                    {t('queries.pageOffset', {
                                        n: pageOffset,
                                        count: result.count,
                                    })}
                                </span>
                                <div className="flex items-center gap-2">
                                    {pageOffset > 0 && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={isRunning}
                                            onClick={() => {
                                                const prev = Math.max(
                                                    0,
                                                    pageOffset - result.count,
                                                );
                                                setPageOffset(prev);
                                                void run(prev);
                                            }}
                                        >
                                            <ChevronLeft className="size-4" />
                                            {t('queries.pagePrev')}
                                        </Button>
                                    )}
                                    {result.hasMore && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={isRunning}
                                            onClick={() => {
                                                const next =
                                                    pageOffset + result.count;
                                                setPageOffset(next);
                                                void run(next);
                                            }}
                                        >
                                            {t('queries.pageNext')}
                                            <ChevronRight className="size-4" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </>
                    )}

                    {isAgent &&
                        agentRun &&
                        (agentRun.status === 'queued' ||
                            agentRun.status === 'running') && (
                            <div className="card card-body space-y-3">
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <Spinner />
                                    {t(
                                        agentRun.status === 'queued'
                                            ? 'queries.agentQueued'
                                            : 'queries.agentRunning',
                                    )}
                                </div>
                                {agentRun.status === 'running' && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('queries.agentProgress', {
                                            iteration: agentRun.iteration,
                                            max: agentRun.max_iterations,
                                            calls: agentRun.oracle_calls_count,
                                        })}
                                    </p>
                                )}
                                <Skeleton className="h-9 w-full" />
                                <Skeleton className="h-9 w-full" />
                            </div>
                        )}

                    {isAgent && agentRun?.status === 'cancelled' && (
                        <Alert>
                            <X />
                            <AlertTitle>
                                {t('queries.agentCancelledTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {t('queries.agentCancelledDescription')}
                            </AlertDescription>
                        </Alert>
                    )}

                    {isAgent &&
                        (agentRun?.status === 'failed' || agent.error) && (
                            <Alert>
                                <AlertTitle>
                                    {t('queries.agentFailedTitle')}
                                </AlertTitle>
                                <AlertDescription>
                                    {agent.error ??
                                        t('queries.agentFailedDescription')}
                                </AlertDescription>
                            </Alert>
                        )}

                    {isAgent &&
                        agentRun?.status === 'completed' &&
                        agentRun.result && (
                            <QueryResultView
                                result={agentRun.result}
                                tenantLabel={
                                    tenants[agentRun.result.tenant] ??
                                    tenantLabel
                                }
                            />
                        )}

                    {/* Lot chaining — dynamic chained queries panel */}
                    {!isAgent && status === 'done' && !fetchError && result && (
                        <ChainedQueryPanel
                            queryId={query.id}
                            primaryItems={
                                (result.items ?? []) as Array<
                                    Record<string, unknown>
                                >
                            }
                            tenant={tenant}
                            tenants={tenants}
                            canManage={query.can.update}
                            accessibleQueries={accessibleQueries}
                        />
                    )}

                    {/* Lot 10C — daily execution trend panel */}
                    <TrendPanel
                        queryId={query.id}
                        aggregatesUrl={`/queries/${query.id}/aggregates`}
                    />
                </div>
            </div>
        </>
    );
}

ShowQuery.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Détail', href: '#' },
    ],
};
