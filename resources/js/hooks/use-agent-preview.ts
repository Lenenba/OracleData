import { useCallback, useEffect, useRef, useState } from 'react';
import type { AgentRunStatus } from '@/hooks/use-agent-run';
import { readCsrfToken } from '@/lib/csrf';
import {
    cancel as cancelAgentRun,
    show as showAgentRun,
} from '@/routes/agent-runs';
import { store as storeAgentPreview } from '@/actions/App/Http/Controllers/QueryAgentPreviewController';

const POLL_INTERVAL_MS = 2000;
const TERMINAL = ['completed', 'failed', 'cancelled'] as const;

function isTerminal(status: string): boolean {
    return (TERMINAL as readonly string[]).includes(status);
}

function postJson(url: string, body?: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCsrfToken(),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

/**
 * Lot 11E — Dispatches an ephemeral agent analysis from a raw intent string
 * (no saved query needed), then polls until terminal, exposing progress and
 * cancellation. The API mirrors useAgentRun to share the same UI widgets.
 */
export function useAgentPreview() {
    const [run, setRun] = useState<AgentRunStatus | null>(null);
    const [dispatching, setDispatching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mountedRef = useRef(true);
    const pollRef = useRef<(id: number) => void>(() => {});

    const clearPoll = useCallback(() => {
        if (timeoutRef.current !== null) {
            clearTimeout(timeoutRef.current);
            timeoutRef.current = null;
        }
    }, []);

    useEffect(() => {
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
            clearPoll();
        };
    }, [clearPoll]);

    const poll = useCallback(async (id: number) => {
        try {
            const response = await fetch(showAgentRun.url(id), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data = (await response
                .json()
                .catch(() => null)) as AgentRunStatus | null;

            if (!mountedRef.current) {
                return;
            }

            if (response.ok && data !== null) {
                setRun(data);

                if (isTerminal(data.status)) {
                    return;
                }
            }
        } catch {
            // Transient hiccup — next tick retries.
        }

        if (mountedRef.current) {
            timeoutRef.current = setTimeout(
                () => pollRef.current(id),
                POLL_INTERVAL_MS,
            );
        }
    }, []);

    useEffect(() => {
        pollRef.current = poll;
    }, [poll]);

    const start = useCallback(
        async (intent: string, tenantKey: string) => {
            clearPoll();
            setError(null);
            setRun(null);
            setDispatching(true);

            try {
                const response = await postJson(storeAgentPreview.url(), {
                    intent,
                    tenant_key: tenantKey,
                });
                const data = (await response
                    .json()
                    .catch(() => null)) as AgentRunStatus | null;

                if (!mountedRef.current) {
                    return;
                }

                if (!response.ok || data === null) {
                    setError(
                        (data as { message?: string } | null)?.message ?? null,
                    );

                    return;
                }

                setRun(data);

                if (!isTerminal(data.status)) {
                    timeoutRef.current = setTimeout(
                        () => poll(data.id),
                        POLL_INTERVAL_MS,
                    );
                }
            } catch {
                if (mountedRef.current) {
                    setError(null);
                }
            } finally {
                if (mountedRef.current) {
                    setDispatching(false);
                }
            }
        },
        [clearPoll, poll],
    );

    const cancel = useCallback(async () => {
        if (run === null || isTerminal(run.status)) {
            return;
        }

        try {
            const response = await postJson(cancelAgentRun.url(run.id));
            const data = (await response
                .json()
                .catch(() => null)) as AgentRunStatus | null;

            if (mountedRef.current && response.ok && data !== null) {
                setRun(data);

                if (isTerminal(data.status)) {
                    clearPoll();
                }
            }
        } catch {
            // Best-effort; polling keeps the UI honest.
        }
    }, [clearPoll, run]);

    const reset = useCallback(() => {
        clearPoll();
        setRun(null);
        setError(null);
        setDispatching(false);
    }, [clearPoll]);

    const isActive = dispatching || (run !== null && !isTerminal(run.status));

    return { run, isActive, dispatching, error, start, cancel, reset };
}
