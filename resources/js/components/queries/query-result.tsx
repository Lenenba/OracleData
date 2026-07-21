import { AlertCircle, HelpCircle, ShieldCheck, ShieldAlert, Shield } from 'lucide-react';
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
    path?: string;
    params: Record<string, unknown> | unknown;
    count: number;
};

export type ConfidenceLevel = 'high' | 'medium' | 'low';

export type QueryResult = {
    mode: 'single' | 'agent' | 'clarify';
    tenant: string;
    resource: QueryResource | null;
    parameters: Record<string, unknown> | null;
    columns: string[] | null;
    analysis: string | null;
    /** Lot 11B — confiance et provenance */
    confidence?: ConfidenceLevel;
    sources_used?: string[];
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
                        {call.resource}
                        {call.path ? ` (${call.path})` : ''} → {call.count}{' '}
                        ligne(s)
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
    columnsOverride,
    childColumnsOverride,
}: {
    result: QueryResult;
    tenantLabel: string;
    /** Colonnes parent visibles (projection client) ; priorité sur result.columns. */
    columnsOverride?: string[];
    /** Colonnes visibles par enfant/jointure imbriqué (projection client). */
    childColumnsOverride?: Record<string, string[]>;
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

            {/* Lot 11B — badge de confiance + sources */}
            {result.mode === 'agent' && result.confidence && (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    {result.confidence === 'high' && (
                        <Badge variant="outline" className="gap-1 border-emerald-500/40 text-emerald-700 dark:text-emerald-400">
                            <ShieldCheck className="size-3" />
                            Confiance élevée
                        </Badge>
                    )}
                    {result.confidence === 'medium' && (
                        <Badge variant="outline" className="gap-1 border-amber-500/40 text-amber-700 dark:text-amber-400">
                            <Shield className="size-3" />
                            Confiance moyenne
                        </Badge>
                    )}
                    {result.confidence === 'low' && (
                        <Badge variant="outline" className="gap-1 border-red-500/40 text-red-700 dark:text-red-400">
                            <ShieldAlert className="size-3" />
                            Confiance faible
                        </Badge>
                    )}
                    {result.sources_used && result.sources_used.length > 0 && (
                        <span className="text-muted-foreground">
                            Sources : {result.sources_used.join(', ')}
                        </span>
                    )}
                </div>
            )}

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
                    columns={columnsOverride ?? result.columns ?? undefined}
                    childColumns={childColumnsOverride}
                />
            )}
        </div>
    );
}
