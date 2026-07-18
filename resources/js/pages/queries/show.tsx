import { Head, Link, router } from '@inertiajs/react';
import { Copy, Pencil } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { QueryResultView } from '@/components/queries/query-result';
import type { QueryResult } from '@/components/queries/query-result';
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
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

type QueryDetail = {
    id: number;
    name: string;
    description: string | null;
    resource_path: string | null;
    tenant_key: string | null;
    mode: 'single' | 'agent';
    parameters: Record<string, unknown>;
    visibility: 'private' | 'shared';
    category: { slug: string; name: string; color: string | null } | null;
    tags: Array<{ slug: string; name: string }>;
    can: { update: boolean; clone: boolean };
};

type ShowProps = {
    query: QueryDetail;
    tenants: Record<string, string>;
    defaultTenant: string;
};

export default function ShowQuery({
    query,
    tenants,
    defaultTenant,
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

    const tenantLabel = tenants[result?.tenant ?? tenant] ?? tenant;

    function cloneQuery() {
        router.post(queries.clone(query.id));
    }

    async function run() {
        setStatus('loading');
        setFetchError(null);
        setResult(null);

        try {
            const response = await fetch(queries.run.url(query.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ tenant }),
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

            <div className="px-6 py-6">
                <Heading
                    title={query.name}
                    description={query.description ?? undefined}
                    actions={
                        <div className="flex flex-wrap gap-2">
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
                        </div>
                    }
                />

                <div className="space-y-6">
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <Badge
                            variant={
                                query.visibility === 'shared'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {query.visibility === 'shared'
                                ? t('queries.sharedBadge')
                                : t('queries.privateBadge')}
                        </Badge>
                        <Badge variant="outline">
                            {query.mode === 'agent'
                                ? t('queries.analysis')
                                : t('queries.queryMode')}
                        </Badge>
                        {query.category && (
                            <Badge
                                variant="outline"
                                style={
                                    query.category.color
                                        ? {
                                              borderColor: query.category.color,
                                              color: query.category.color,
                                          }
                                        : undefined
                                }
                            >
                                {query.category.name}
                            </Badge>
                        )}
                        {query.tags.map((tag) => (
                            <Badge key={tag.slug} variant="secondary">
                                {tag.name}
                            </Badge>
                        ))}
                        <Badge variant="outline">
                            {tenants[query.tenant_key ?? ''] ??
                                query.tenant_key ??
                                t('queries.noEnvironment')}
                        </Badge>
                        {query.resource_path && (
                            <code className="text-xs">
                                {query.resource_path}
                            </code>
                        )}
                    </div>

                    <div className="flex flex-wrap items-end gap-3 rounded-xl border p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="tenant">
                                {t('queries.environment')}
                            </Label>
                            <Select value={tenant} onValueChange={setTenant}>
                                <SelectTrigger id="tenant" className="w-64">
                                    <SelectValue
                                        placeholder={t(
                                            'queries.chooseEnvironment',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {tenantKeys.map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {tenants[key]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <Button
                            onClick={run}
                            disabled={status === 'loading' || tenant === ''}
                        >
                            {status === 'loading' && <Spinner />}
                            {t('queries.run')}
                        </Button>
                    </div>

                    {status === 'loading' && (
                        <div className="space-y-2">
                            <Skeleton className="h-9 w-full" />
                            <Skeleton className="h-9 w-full" />
                            <Skeleton className="h-9 w-full" />
                        </div>
                    )}

                    {status === 'done' && fetchError && (
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

                    {status === 'done' && !fetchError && result && (
                        <QueryResultView
                            result={result}
                            tenantLabel={tenantLabel}
                        />
                    )}
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
