import { Bot, ChevronRight, LoaderCircle, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { suggest as suggestCopilot } from '@/actions/App/Http/Controllers/QueryCopilotController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import type { ResourceSuggestion } from '@/lib/query-spec';

type CopilotResource = {
    key: string;
    label: string;
    domain: string;
    score: number;
};

type SuggestionResult = {
    resources: CopilotResource[];
    suggestion: {
        mode: string;
        query?: { resource?: string };
        plan?: string;
        question?: string;
    };
};

type Props = {
    intent: string;
    allResources: ResourceSuggestion[];
    onSelectResource: (resource: ResourceSuggestion) => void;
};

const DEBOUNCE_MS = 700;
const MIN_INTENT_LENGTH = 20;

/**
 * Lot 11D — Copilot suggestion panel for the query builder.
 *
 * Appears when the description / intent field has ≥ 20 characters.
 * Calls the LLM (no Oracle) to suggest matching resources and plan mode.
 * The user can click a suggestion to jump-select it in the builder.
 */
export function CopilotSuggestionPanel({ intent, allResources, onSelectResource }: Props) {
    const { t } = useI18n();
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<SuggestionResult | null>(null);
    const [dismissed, setDismissed] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const lastIntentRef = useRef('');

    useEffect(() => {
        // Reset dismissed when intent changes significantly
        if (Math.abs(intent.length - lastIntentRef.current.length) > 10) {
            setDismissed(false);
        }

        if (timerRef.current !== null) {
            clearTimeout(timerRef.current);
        }

        if (intent.trim().length < MIN_INTENT_LENGTH) {
            setResult(null);
            setLoading(false);

            return;
        }

        timerRef.current = setTimeout(() => {
            void fetchSuggestions(intent);
        }, DEBOUNCE_MS);

        return () => {
            if (timerRef.current !== null) {
                clearTimeout(timerRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [intent]);

    async function fetchSuggestions(currentIntent: string): Promise<void> {
        lastIntentRef.current = currentIntent;

        if (abortRef.current !== null) {
            abortRef.current.abort();
        }

        const controller = new AbortController();
        abortRef.current = controller;
        setLoading(true);

        try {
            const response = await fetch(suggestCopilot.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                signal: controller.signal,
                body: JSON.stringify({ intent: currentIntent }),
            });

            if (!response.ok) {
                setResult(null);

                return;
            }

            const data = (await response.json()) as SuggestionResult;
            setResult(data);
        } catch {
            // AbortError or network hiccup — silently ignore
        } finally {
            setLoading(false);
        }
    }

    if (intent.trim().length < MIN_INTENT_LENGTH || dismissed) {
        return null;
    }

    if (!loading && result === null) {
        return null;
    }

    function resourceForKey(key: string): ResourceSuggestion | undefined {
        return allResources.find((r) => r.key === key);
    }

    return (
        <div className="rounded-lg border border-dashed bg-muted/30 p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
                <div className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <Bot className="size-3.5" />
                    {loading
                        ? t('copilot.analyzing')
                        : t('copilot.suggestions')}
                    {loading && (
                        <LoaderCircle className="size-3 animate-spin" />
                    )}
                </div>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    className="text-muted-foreground hover:text-foreground"
                    aria-label={t('common.dismiss')}
                >
                    <X className="size-3.5" />
                </button>
            </div>

            {result !== null && !loading && (
                <>
                    {result.suggestion.mode === 'agent' && (
                        <p className="mb-2 text-xs text-muted-foreground">
                            {t('copilot.agentMode')}
                        </p>
                    )}

                    {result.suggestion.mode === 'clarify' &&
                        result.suggestion.question && (
                            <p className="mb-2 text-xs text-amber-700 dark:text-amber-400">
                                {result.suggestion.question}
                            </p>
                        )}

                    {result.resources.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {result.resources
                                .filter((r) => r.score > 0)
                                .slice(0, 5)
                                .map((r) => {
                                    const full = resourceForKey(r.key);

                                    return (
                                        <button
                                            key={r.key}
                                            type="button"
                                            disabled={full === undefined}
                                            onClick={() => {
                                                if (full !== undefined) {
                                                    onSelectResource(full);
                                                    setDismissed(true);
                                                }
                                            }}
                                            className="group flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-xs transition-colors hover:border-primary/60 hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-40"
                                        >
                                            <span>{r.label}</span>
                                            <Badge
                                                variant="outline"
                                                className="text-[10px] py-0"
                                            >
                                                {r.domain}
                                            </Badge>
                                            <ChevronRight className="size-3 text-muted-foreground group-hover:text-primary" />
                                        </button>
                                    );
                                })}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
