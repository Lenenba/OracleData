import { AlertCircle, HelpCircle } from 'lucide-react';
import AlertError from '@/components/alert-error';
import { ResultsTable } from '@/components/queries/results-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';

export type QueryResource = {
    key: string;
    label: string;
    description?: string;
    domain?: string;
    method?: string;
    path: string;
};

export type OracleCall = {
    resource: string;
    params: Record<string, unknown> | unknown;
    count: number;
};

export type QueryResult = {
    mode: 'single' | 'agent' | 'clarify';
    tenant: string;
    resource: QueryResource | null;
    parameters: Record<string, unknown> | null;
    columns: string[] | null;
    analysis: string | null;
    items: Record<string, unknown>[];
    count: number;
    hasMore: boolean;
    oracleCalls: OracleCall[];
    clarification: string | null;
    error: string | null;
};

function OracleCalls({ calls }: { calls: OracleCall[] }) {
    if (calls.length === 0) {
        return null;
    }

    return (
        <details className="rounded-lg border bg-muted/30 px-3 py-2 text-xs">
            <summary className="cursor-pointer text-muted-foreground">
                {calls.length} appel(s) Oracle
            </summary>
            <ul className="mt-2 space-y-1">
                {calls.map((call, index) => (
                    <li key={index} className="font-mono">
                        {call.resource} → {call.count} ligne(s)
                    </li>
                ))}
            </ul>
        </details>
    );
}

/**
 * Rendu unifié d'un résultat de requête, quel que soit le mode (single,
 * agent multi-ressources, ou demande de clarification).
 */
export function QueryResultView({
    result,
    tenantLabel,
}: {
    result: QueryResult;
    tenantLabel: string;
}) {
    if (result.error) {
        return (
            <AlertError title="La requête a échoué" errors={[result.error]} />
        );
    }

    if (result.mode === 'clarify') {
        return (
            <Alert>
                <HelpCircle />
                <AlertTitle>Précision nécessaire</AlertTitle>
                <AlertDescription>
                    {result.clarification ??
                        'Pouvez-vous préciser votre demande ?'}
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <Badge variant="secondary">
                    {result.mode === 'agent' ? 'Analyse' : 'Requête'}
                </Badge>
                <span>
                    {result.count} résultat(s) depuis{' '}
                    <span className="font-medium text-foreground">
                        {tenantLabel}
                    </span>
                    {result.hasMore && ' (plus de résultats disponibles)'}
                </span>
            </div>

            {result.analysis && (
                <Alert>
                    <AlertCircle />
                    <AlertTitle>Analyse</AlertTitle>
                    <AlertDescription>{result.analysis}</AlertDescription>
                </Alert>
            )}

            <OracleCalls calls={result.oracleCalls} />

            {result.items.length === 0 ? (
                <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                    Aucun résultat pour cette requête.
                </div>
            ) : (
                <ResultsTable
                    items={result.items}
                    columns={result.columns ?? undefined}
                />
            )}
        </div>
    );
}
