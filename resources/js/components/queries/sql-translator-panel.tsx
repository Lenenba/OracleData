import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Code2,
    LoaderCircle,
    Play,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { TranslationKey } from '@/i18n/i18n-context';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';

// ── Types ─────────────────────────────────────────────────────────────────────

type PlanFragment = {
    type: 'partial' | 'impossible';
    message: string;
    sql_fragment: string;
};

type ApiCall = {
    resource_key: string;
    fields: string[];
    q: string;
    orderBy: string;
    limit: number | null;
    offset: number | null;
    joins: string[];
};

type TranslationPlan = {
    equivalence: 'exact' | 'partial' | 'impossible';
    confidence: number;
    calls: ApiCall[];
    fragments: PlanFragment[];
    resource_key: string;
    fields: string[];
    q: string;
    orderBy: string;
    limit: number | null;
    offset: number | null;
    joins: string[];
};

type OracleResult = {
    items: unknown[];
    count: number;
    hasMore: boolean;
    resource: { key: string; label: string };
    calls: unknown[];
};

type TranslateResponse = {
    equivalence: 'exact' | 'partial' | 'impossible';
    confidence: number;
    plan: TranslationPlan | null;
    fragments: PlanFragment[];
    error?: string;
    error_message?: string;
    result?: OracleResult;
};

type Props = {
    tenants: Record<string, string>;
    defaultTenant: string;
    translateUrl: string;
    translateRunUrl: string;
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function equivalenceBadge(eq: string) {
    if (eq === 'exact') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
                <CheckCircle2 className="size-3" />
                exact
            </span>
        );
    }

    if (eq === 'partial') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                <AlertTriangle className="size-3" />
                partial
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300">
            <XCircle className="size-3" />
            impossible
        </span>
    );
}

function PlanSummary({ plan }: { plan: TranslationPlan }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const call = plan.calls[0];

    if (call === undefined) return null;

    const params: string[] = [];
    if (call.fields.length > 0) params.push(`fields=${call.fields.join(',')}`);
    if (call.q) params.push(`q=${call.q}`);
    if (call.orderBy) params.push(`orderBy=${call.orderBy}`);
    if (call.limit !== null) params.push(`limit=${call.limit}`);
    if (call.offset !== null) params.push(`offset=${call.offset}`);
    if (call.joins.length > 0) params.push(`joins=${call.joins.join(',')}`);

    return (
        <div className="rounded-lg border bg-muted/30 p-3 text-xs">
            <button
                type="button"
                className="flex w-full items-center gap-1.5 font-medium"
                onClick={() => setOpen((v) => !v)}
            >
                {open ? (
                    <ChevronDown className="size-3.5" />
                ) : (
                    <ChevronRight className="size-3.5" />
                )}
                {t('sqlTranslator.planTitle')} — {call.resource_key}
            </button>

            {open && (
                <div className="mt-2 space-y-1 font-mono text-[11px] text-muted-foreground">
                    {params.map((p, i) => (
                        <div key={i} className="break-all">
                            {p}
                        </div>
                    ))}
                    {params.length === 0 && (
                        <div className="italic">
                            {t('sqlTranslator.planNoParams')}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function FragmentList({ fragments }: { fragments: PlanFragment[] }) {
    const { t } = useI18n();

    if (fragments.length === 0) return null;

    return (
        <div className="space-y-1">
            <p className="text-xs font-medium text-muted-foreground">
                {t('sqlTranslator.fragmentsTitle')}
            </p>
            {fragments.map((f, i) => (
                <div
                    key={i}
                    className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs dark:border-amber-800 dark:bg-amber-950/30"
                >
                    <AlertCircle className="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div className="min-w-0">
                        <span className="text-amber-800 dark:text-amber-300">
                            {t(f.message as TranslationKey, { fragment: f.sql_fragment })}
                        </span>
                        <span className="ml-1 font-mono text-amber-600 dark:text-amber-400">
                            {f.sql_fragment}
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

/**
 * Lot 11C — SQL standard → Oracle REST API translator.
 *
 * Two steps:
 *  1. Translate: sends the SQL to /queries/sql-translate, shows equivalence
 *     level, plan and diagnostic fragments. No Oracle call.
 *  2. Run: sends the SQL + tenant + accepted equivalence level to
 *     /queries/sql-translate/run, executes via Oracle REST and shows results.
 */
export function SqlTranslatorPanel({
    tenants,
    defaultTenant,
    translateUrl,
    translateRunUrl,
}: Props) {
    const { t } = useI18n();
    const tenantKeys = Object.keys(tenants);

    const [sql, setSql] = useState('');
    const [tenant, setTenant] = useState(defaultTenant);
    const [loading, setLoading] = useState(false);
    const [runLoading, setRunLoading] = useState(false);
    const [response, setResponse] = useState<TranslateResponse | null>(null);
    const [runResponse, setRunResponse] = useState<TranslateResponse | null>(
        null,
    );
    const [acceptEquivalence, setAcceptEquivalence] = useState<
        'exact' | 'partial'
    >('exact');

    async function handleTranslate() {
        if (sql.trim().length < 10) return;
        setLoading(true);
        setResponse(null);
        setRunResponse(null);

        try {
            const res = await fetch(translateUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ sql }),
            });

            const data = (await res.json()) as TranslateResponse;
            setResponse(data);
        } finally {
            setLoading(false);
        }
    }

    async function handleRun() {
        if (sql.trim().length < 10) return;
        setRunLoading(true);
        setRunResponse(null);

        try {
            const res = await fetch(translateRunUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    sql,
                    tenant_key: tenant,
                    accept_equivalence: acceptEquivalence,
                }),
            });

            const data = (await res.json()) as TranslateResponse;
            setRunResponse(data);
        } finally {
            setRunLoading(false);
        }
    }

    const canRun =
        response !== null &&
        response.plan !== null &&
        (response.equivalence === 'exact' || response.equivalence === 'partial');

    const displayResponse = runResponse ?? response;

    return (
        <div className="space-y-4">
            {/* Header */}
            <div className="flex items-center gap-2">
                <Code2 className="size-4 text-muted-foreground" />
                <div>
                    <p className="text-sm font-medium">
                        {t('sqlTranslator.title')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t('sqlTranslator.description')}
                    </p>
                </div>
            </div>

            {/* SQL input */}
            <div className="space-y-1.5">
                <Textarea
                    value={sql}
                    onChange={(e) => {
                        setSql(e.target.value);
                        setResponse(null);
                        setRunResponse(null);
                    }}
                    placeholder={t('sqlTranslator.placeholder')}
                    rows={6}
                    className="font-mono text-xs"
                    spellCheck={false}
                />
                <p className="text-[11px] text-muted-foreground">
                    {t('sqlTranslator.hint')}
                </p>
            </div>

            {/* Translate button */}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={loading || sql.trim().length < 10}
                onClick={() => void handleTranslate()}
                className="gap-1.5"
            >
                {loading ? (
                    <LoaderCircle className="size-3.5 animate-spin" />
                ) : (
                    <Code2 className="size-3.5" />
                )}
                {loading
                    ? t('sqlTranslator.translating')
                    : t('sqlTranslator.translate')}
            </Button>

            {/* Translation result */}
            {displayResponse !== null && (
                <div className="space-y-3">
                    {/* Error */}
                    {displayResponse.error !== undefined && (
                        <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs dark:border-red-800 dark:bg-red-950/30">
                            <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                            <div>
                                <p className="font-medium text-red-800 dark:text-red-300">
                                    {t('sqlTranslator.errorTitle')}
                                </p>
                                <p className="text-red-700 dark:text-red-400">
                                    {displayResponse.error_message ??
                                        t(displayResponse.error as TranslationKey)}
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Equivalence + plan */}
                    {displayResponse.plan !== null &&
                        displayResponse.error === undefined && (
                            <div className="space-y-2">
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground">
                                        {t('sqlTranslator.equivalence')}
                                    </span>
                                    {equivalenceBadge(
                                        displayResponse.equivalence,
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {t('sqlTranslator.confidence', {
                                            value: String(
                                                displayResponse.confidence,
                                            ),
                                        })}
                                    </span>
                                </div>

                                {displayResponse.plan !== null && (
                                    <PlanSummary plan={displayResponse.plan} />
                                )}

                                <FragmentList
                                    fragments={displayResponse.fragments}
                                />
                            </div>
                        )}

                    {/* Execution result */}
                    {displayResponse.result !== undefined && (
                        <div className="space-y-1">
                            <p className="text-xs font-medium">
                                {t('sqlTranslator.resultTitle', {
                                    count: String(
                                        displayResponse.result.count,
                                    ),
                                })}
                            </p>
                            <div className="max-h-64 overflow-auto rounded-md border bg-muted/20 p-2 text-[11px] font-mono">
                                <pre className="whitespace-pre-wrap break-all">
                                    {JSON.stringify(
                                        displayResponse.result.items.slice(
                                            0,
                                            10,
                                        ),
                                        null,
                                        2,
                                    )}
                                </pre>
                                {displayResponse.result.hasMore && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {t('sqlTranslator.resultHasMore')}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Run section */}
            {canRun && (
                <div className="rounded-lg border p-3 space-y-3">
                    <p className="text-xs font-medium">
                        {t('sqlTranslator.runTitle')}
                    </p>

                    <div className="flex flex-wrap items-end gap-3">
                        {/* Tenant selector */}
                        <div className="space-y-1">
                            <label className="text-[11px] text-muted-foreground">
                                {t('queries.tenant')}
                            </label>
                            <Select value={tenant} onValueChange={setTenant}>
                                <SelectTrigger className="h-8 w-52 text-xs">
                                    <SelectValue />
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

                        {/* Accept equivalence */}
                        <div className="space-y-1">
                            <label className="text-[11px] text-muted-foreground">
                                {t('sqlTranslator.acceptEquivalence')}
                            </label>
                            <Select
                                value={acceptEquivalence}
                                onValueChange={(v) =>
                                    setAcceptEquivalence(
                                        v as 'exact' | 'partial',
                                    )
                                }
                            >
                                <SelectTrigger className="h-8 w-36 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="exact">
                                        {t(
                                            'sqlTranslator.acceptEquivalenceExact',
                                        )}
                                    </SelectItem>
                                    <SelectItem value="partial">
                                        {t(
                                            'sqlTranslator.acceptEquivalencePartial',
                                        )}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Run button */}
                        <Button
                            type="button"
                            size="sm"
                            disabled={runLoading}
                            onClick={() => void handleRun()}
                            className="h-8 gap-1.5"
                        >
                            {runLoading ? (
                                <LoaderCircle className="size-3.5 animate-spin" />
                            ) : (
                                <Play className="size-3.5" />
                            )}
                            {runLoading
                                ? t('sqlTranslator.running')
                                : t('sqlTranslator.run')}
                        </Button>
                    </div>

                    {/* Equivalence warning for partial */}
                    {response?.equivalence === 'partial' && (
                        <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs dark:border-amber-800 dark:bg-amber-950/30">
                            <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-amber-600" />
                            <span className="text-amber-800 dark:text-amber-200">
                                {t('sqlTranslator.partialWarning')}
                            </span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
