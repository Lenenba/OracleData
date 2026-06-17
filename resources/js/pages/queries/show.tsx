import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { ResultsTable } from '@/components/queries/results-table';
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
import queries from '@/routes/queries';

type QueryDetail = {
    id: number;
    name: string;
    description: string | null;
    resource_path: string;
    parameters: Record<string, unknown>;
    visibility: 'private' | 'shared';
    can: { update: boolean };
};

type RunResult = {
    tenant: string;
    items: Record<string, unknown>[];
    count: number;
    hasMore: boolean;
    error: string | null;
};

type ShowProps = {
    query: QueryDetail;
    tenants: Record<string, string>;
    defaultTenant: string;
};

function readCsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export default function ShowQuery({
    query,
    tenants,
    defaultTenant,
}: ShowProps) {
    const tenantKeys = Object.keys(tenants);
    const [tenant, setTenant] = useState(
        tenantKeys.includes(defaultTenant)
            ? defaultTenant
            : (tenantKeys[0] ?? ''),
    );
    const [status, setStatus] = useState<'idle' | 'loading' | 'done'>('idle');
    const [result, setResult] = useState<RunResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function run() {
        setStatus('loading');
        setError(null);
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

            if (!response.ok) {
                const data = await response.json().catch(() => null);
                setError(
                    data?.errors?.tenant?.[0] ??
                        data?.message ??
                        "Échec de l'exécution de la requête.",
                );
                setStatus('done');

                return;
            }

            const data: RunResult = await response.json();
            setResult(data);
            setError(data.error);
            setStatus('done');
        } catch {
            setError('Erreur réseau lors de la communication avec le serveur.');
            setStatus('done');
        }
    }

    return (
        <>
            <Head title={query.name} />

            <div className="space-y-6 px-4 py-6">
                <Heading
                    title={query.name}
                    description={query.description ?? undefined}
                />

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
                    <code className="text-xs">{query.resource_path}</code>
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

                {status === 'done' && error && (
                    <AlertError title="La requête a échoué" errors={[error]} />
                )}

                {status === 'done' && !error && result && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {result.count} résultat(s) depuis{' '}
                            <span className="font-medium text-foreground">
                                {tenants[result.tenant] ?? result.tenant}
                            </span>
                            {result.hasMore &&
                                ' (plus de résultats disponibles)'}
                        </p>

                        {result.items.length === 0 ? (
                            <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                                Aucun résultat pour cette requête.
                            </div>
                        ) : (
                            <ResultsTable items={result.items} />
                        )}
                    </div>
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
