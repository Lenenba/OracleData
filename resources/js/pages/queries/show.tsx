import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
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
    can: { update: boolean };
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
                        "Échec de l'exécution de la requête.",
                );
                setStatus('done');

                return;
            }

            setResult(data);
            setStatus('done');
        } catch {
            setFetchError(
                'Erreur réseau lors de la communication avec le serveur.',
            );
            setStatus('done');
        }
    }

    return (
        <>
            <Head title={query.name} />

            <div className="space-y-6 px-4 py-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title={query.name}
                        description={query.description ?? undefined}
                    />
                    {query.can.update && (
                        <Button asChild variant="outline" size="sm" className="shrink-0">
                            <Link href={queries.edit(query.id)}>
                                <Pencil className="size-3.5" />
                                Modifier
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <Badge
                        variant={
                            query.visibility === 'shared'
                                ? 'default'
                                : 'secondary'
                        }
                    >
                        {query.visibility === 'shared' ? 'Partagée' : 'Privée'}
                    </Badge>
                    <Badge variant="outline">
                        {query.mode === 'agent' ? 'Analyse' : 'Requête'}
                    </Badge>
                    <Badge variant="outline">
                        {tenants[query.tenant_key ?? ''] ??
                            query.tenant_key ??
                            'Aucun tenant'}
                    </Badge>
                    {query.resource_path && (
                        <code className="text-xs">{query.resource_path}</code>
                    )}
                </div>

                <div className="flex flex-wrap items-end gap-3 rounded-xl border p-4">
                    <div className="grid gap-2">
                        <Label htmlFor="tenant">Environnement (tenant)</Label>
                        <Select value={tenant} onValueChange={setTenant}>
                            <SelectTrigger id="tenant" className="w-64">
                                <SelectValue placeholder="Choisir un client" />
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
                        Exécuter
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
        </>
    );
}

ShowQuery.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Détail', href: '#' },
    ],
};
