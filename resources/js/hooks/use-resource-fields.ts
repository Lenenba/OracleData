import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useSyncExternalStore } from 'react';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

export type ResourceFields = {
    /** Fields to display: tenant discovery when available, otherwise catalog. */
    fields: string[];
    /** `live` for discovered fields, `catalog` for the fallback response. */
    source: 'live' | 'catalog';
    /** Always false because catalog fields are displayed without blocking. */
    loading: boolean;
};

type CacheEntry = Pick<ResourceFields, 'fields' | 'source'>;

type CacheRecord = {
    entry: CacheEntry;
    resolvedAt: number;
};

type ProbeAttempt = {
    entry: CacheEntry | null;
    retryable: boolean;
};

const CATALOG_CACHE_TTL_MS = 60_000;
const REQUEST_TIMEOUT_MS = 15_000;
const RETRY_DELAY_MS = 1_500;

/** Successful server responses cached for the duration of the page visit. */
const resolved = new Map<string, CacheRecord>();

/** One shared request per tenant/resource/child combination. */
const inFlight = new Map<string, Promise<CacheEntry | null>>();

/** Subscribers keep every mounted hook instance synchronized with the cache. */
const subscribers = new Map<string, Set<() => void>>();

function createCacheKey(
    userId: number,
    tenant: string,
    resourceKey: string | null,
    child: string | null,
): string {
    return JSON.stringify([userId, tenant, resourceKey, child]);
}

function getResolved(cacheKey: string): CacheEntry | undefined {
    return resolved.get(cacheKey)?.entry;
}

function hasFreshResolution(cacheKey: string): boolean {
    const record = resolved.get(cacheKey);

    if (record === undefined) {
        return false;
    }

    return (
        record.entry.source === 'live' ||
        Date.now() - record.resolvedAt < CATALOG_CACHE_TTL_MS
    );
}

function isCacheEntry(value: unknown): value is CacheEntry {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        Array.isArray(candidate.fields) &&
        candidate.fields.every((field) => typeof field === 'string') &&
        (candidate.source === 'live' || candidate.source === 'catalog')
    );
}

function isRetryableStatus(status: number): boolean {
    return status === 408 || status === 425 || status === 429 || status >= 500;
}

function subscribe(cacheKey: string, onStoreChange: () => void): () => void {
    const listeners = subscribers.get(cacheKey) ?? new Set<() => void>();
    listeners.add(onStoreChange);
    subscribers.set(cacheKey, listeners);

    return () => {
        listeners.delete(onStoreChange);

        if (listeners.size === 0) {
            subscribers.delete(cacheKey);
        }
    };
}

function publish(cacheKey: string): void {
    subscribers.get(cacheKey)?.forEach((listener) => listener());
}

function emptyServerSnapshot(): undefined {
    return undefined;
}

async function probeOnce(
    tenant: string,
    resourceKey: string,
    child: string | null,
): Promise<ProbeAttempt> {
    const controller = new AbortController();
    const timeout = window.setTimeout(
        () => controller.abort(),
        REQUEST_TIMEOUT_MS,
    );

    try {
        const response = await fetch(queries.resourceFields.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            credentials: 'same-origin',
            signal: controller.signal,
            body: JSON.stringify({
                tenant,
                resource_key: resourceKey,
                child: child ?? undefined,
            }),
        });

        if (!response.ok) {
            return {
                entry: null,
                retryable: isRetryableStatus(response.status),
            };
        }

        const data: unknown = await response.json().catch(() => null);

        if (!isCacheEntry(data)) {
            return { entry: null, retryable: false };
        }

        return {
            entry: {
                fields: Array.from(new Set(data.fields)),
                source: data.source,
            },
            retryable: false,
        };
    } catch {
        return { entry: null, retryable: !controller.signal.aborted };
    } finally {
        window.clearTimeout(timeout);
    }
}

async function probeWithRetry(
    tenant: string,
    resourceKey: string,
    child: string | null,
): Promise<CacheEntry | null> {
    let attempt = await probeOnce(tenant, resourceKey, child);

    if (attempt.entry !== null || !attempt.retryable) {
        return attempt.entry;
    }

    await new Promise<void>((resolve) => {
        window.setTimeout(resolve, RETRY_DELAY_MS);
    });

    attempt = await probeOnce(tenant, resourceKey, child);

    return attempt.entry;
}

function loadResourceFields(
    cacheKey: string,
    tenant: string,
    resourceKey: string,
    child: string | null,
): Promise<CacheEntry | null> {
    const pending = inFlight.get(cacheKey);

    if (pending !== undefined) {
        return pending;
    }

    const request = probeWithRetry(tenant, resourceKey, child)
        .then((entry) => {
            if (entry !== null) {
                resolved.set(cacheKey, {
                    entry,
                    resolvedAt: Date.now(),
                });
                publish(cacheKey);
            }

            return entry;
        })
        .catch(() => null)
        .finally(() => {
            inFlight.delete(cacheKey);
        });

    inFlight.set(cacheKey, request);

    return request;
}

/**
 * Discovers fields exposed by a resource or expanded child on the selected
 * tenant. Catalog fields remain available immediately while the shared request
 * runs in the background, so field selection never depends on a spinner.
 */
export function useResourceFields(
    resourceKey: string | null,
    tenant: string,
    child: string | null,
    fallback: string[],
): ResourceFields {
    const userId = usePage().props.auth.user.id;
    const ready = resourceKey !== null && resourceKey !== '' && tenant !== '';
    const cacheKey = createCacheKey(userId, tenant, resourceKey, child);
    const subscribeToCache = useCallback(
        (onStoreChange: () => void) => subscribe(cacheKey, onStoreChange),
        [cacheKey],
    );
    const getSnapshot = useCallback(
        () => (ready ? getResolved(cacheKey) : undefined),
        [cacheKey, ready],
    );
    const cached = useSyncExternalStore(
        subscribeToCache,
        getSnapshot,
        emptyServerSnapshot,
    );

    useEffect(() => {
        if (!ready || resourceKey === null || hasFreshResolution(cacheKey)) {
            return;
        }

        void loadResourceFields(cacheKey, tenant, resourceKey, child);
    }, [cacheKey, ready, resourceKey, tenant, child]);

    if (!ready || cached === undefined) {
        return { fields: fallback, source: 'catalog', loading: false };
    }

    return { ...cached, loading: false };
}
