import { useCallback, useEffect, useRef, useState } from 'react';
import type { QueryResult } from '@/components/queries/query-result';
import { readCsrfToken } from '@/lib/csrf';
import {
    cancel as cancelAgentRun,
    show as showAgentRun,
} from '@/routes/agent-runs';
import { store as storeAgentRun } from '@/routes/queries/agent-runs';

export type AgentRunPhase =
    | 'queued'
    | 'running'
    | 'completed'
    | 'failed'
    | 'cancelled';

export type AgentRunStatus = {
    id: number;
    status: AgentRunPhase;
    iteration: number;
    max_iterations: number;
    oracle_calls_count: number;
    row_count: number;
    error_code: string | null;
    result: QueryResult | null;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
};

const POLL_INTERVAL_MS = 2000;
const TERMINAL: AgentRunPhase[] = ['completed', 'failed', 'cancelled'];

function isTerminal(status: AgentRunPhase): boolean {
    return TERMINAL.includes(status);
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
 * Dispatches an asynchronous agent analysis for one query, then polls its
 * status until it reaches a terminal state, exposing progress and cancellation.
 */
export function useAgentRun(queryId: number) {
    const [run, setRun] = useState<AgentRunStatus | null>(null);
    const [dispatching, setDispatching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mountedRef = useRef(true);
    // Holds the latest poll function so the scheduled tick can reference it
    // without the callback capturing itself before it is declared.
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
            // A transient hiccup should not abort the run; the next tick retries.
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
        async (tenant: string) => {
            clearPoll();
            setError(null);
            setRun(null);
            setDispatching(true);

            try {
                const response = await postJson(storeAgentRun.url(queryId), {
                    tenant,
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
        [clearPoll, poll, queryId],
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
            // The cooperative flag is best-effort; polling keeps the UI honest.
        }
    }, [clearPoll, run]);

    const isActive = dispatching || (run !== null && !isTerminal(run.status));

    return { run, isActive, dispatching, error, start, cancel };
}
