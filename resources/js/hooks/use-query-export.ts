import { useCallback, useEffect, useRef, useState } from 'react';
import { readCsrfToken } from '@/lib/csrf';
import { cancel as cancelExport, show as showExport } from '@/routes/exports';
import { store as storeExport } from '@/routes/queries/exports';

export type QueryExportPhase =
    | 'queued'
    | 'running'
    | 'completed'
    | 'failed'
    | 'cancelled';

export type QueryExportStatus = {
    id: number;
    status: QueryExportPhase;
    row_count: number;
    truncated: boolean;
    error_code: string | null;
    downloadable: boolean;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
};

const POLL_INTERVAL_MS = 2000;
const TERMINAL: QueryExportPhase[] = ['completed', 'failed', 'cancelled'];

function isTerminal(status: QueryExportPhase): boolean {
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
 * Dispatches a server-side export for one query (CSV, XLSX or JSON), then
 * polls its status until it reaches a terminal state, exposing progress and
 * cancellation. Lot 10D adds the `format` parameter.
 */
export function useQueryExport(queryId: number) {
    const [record, setRecord] = useState<QueryExportStatus | null>(null);
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
            const response = await fetch(showExport.url(id), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            const data = (await response
                .json()
                .catch(() => null)) as QueryExportStatus | null;

            if (!mountedRef.current) {
                return;
            }

            if (response.ok && data !== null) {
                setRecord(data);

                if (isTerminal(data.status)) {
                    return;
                }
            }
        } catch {
            // A transient hiccup should not abort the export; the next tick retries.
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
        async (tenant: string, format: 'csv' | 'xlsx' | 'json' = 'csv') => {
            clearPoll();
            setError(null);
            setRecord(null);
            setDispatching(true);

            try {
                const response = await postJson(storeExport.url(queryId), {
                    tenant,
                    format,
                });
                const data = (await response
                    .json()
                    .catch(() => null)) as QueryExportStatus | null;

                if (!mountedRef.current) {
                    return;
                }

                if (!response.ok || data === null) {
                    setError(
                        (data as { message?: string } | null)?.message ?? null,
                    );

                    return;
                }

                setRecord(data);

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
        if (record === null || isTerminal(record.status)) {
            return;
        }

        try {
            const response = await postJson(cancelExport.url(record.id));
            const data = (await response
                .json()
                .catch(() => null)) as QueryExportStatus | null;

            if (mountedRef.current && response.ok && data !== null) {
                setRecord(data);

                if (isTerminal(data.status)) {
                    clearPoll();
                }
            }
        } catch {
            // The cooperative flag is best-effort; polling keeps the UI honest.
        }
    }, [clearPoll, record]);

    const isActive =
        dispatching || (record !== null && !isTerminal(record.status));

    return { record, isActive, dispatching, error, start, cancel };
}
