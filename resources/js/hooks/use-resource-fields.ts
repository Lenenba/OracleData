import { useEffect, useState } from 'react';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

export type ResourceFields = {
    /** Champs à afficher : découverte tenant, sinon repli catalogue. */
    fields: string[];
    /** `live` = sonde tenant réussie ; `catalog` = repli. */
    source: 'live' | 'catalog';
    /** Vrai pendant la sonde initiale de cette combinaison. */
    loading: boolean;
};

type CacheEntry = { fields: string[]; source: 'live' | 'catalog' };

const RETRY_DELAY_MS = 1500;

/**
 * Cache module par combinaison tenant/ressource/enfant, partagé entre toutes
 * les instances du builder pour la durée de la visite. Seules les réponses
 * serveur abouties y entrent : un échec réseau ou HTTP reste local à
 * l'instance et sera retenté au prochain montage.
 */
const discovered = new Map<string, CacheEntry>();

/** Une seule sonde en vol par clé, partagée entre instances concurrentes. */
const inFlight = new Map<string, Promise<CacheEntry | null>>();

async function probeOnce(
    tenant: string,
    resourceKey: string,
    child: string | null,
): Promise<CacheEntry | null> {
    try {
        const res = await fetch(queries.resourceFields.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                tenant,
                resource_key: resourceKey,
                child: child ?? undefined,
            }),
        });
        const data = (await res.json().catch(() => null)) as {
            fields?: unknown;
            source?: unknown;
        } | null;

        if (
            res.ok &&
            data !== null &&
            Array.isArray(data.fields) &&
            data.fields.every((f) => typeof f === 'string')
        ) {
            return {
                fields: data.fields,
                source: data.source === 'live' ? 'live' : 'catalog',
            };
        }

        return null;
    } catch {
        return null;
    }
}

/**
 * Sonde avec une relance : un « database is locked » ou un 429 ponctuel ne
 * doit pas figer le repli catalogue pour toute la session.
 */
async function probeWithRetry(
    cacheKey: string,
    tenant: string,
    resourceKey: string,
    child: string | null,
): Promise<CacheEntry | null> {
    try {
        let entry = await probeOnce(tenant, resourceKey, child);

        if (entry === null) {
            await new Promise((resolve) =>
                setTimeout(resolve, RETRY_DELAY_MS),
            );
            entry = await probeOnce(tenant, resourceKey, child);
        }

        if (entry !== null) {
            discovered.set(cacheKey, entry);
        }

        return entry;
    } finally {
        inFlight.delete(cacheKey);
    }
}

/**
 * Découvre les champs réellement exposés par une ressource (ou un enfant
 * expand) sur le tenant choisi, via l'endpoint resource-fields (sonde
 * `limit=1` cachée côté serveur). En cas d'échec durable, la liste du
 * catalogue fournie en repli reste affichée sans erreur bloquante.
 */
export function useResourceFields(
    resourceKey: string | null,
    tenant: string,
    child: string | null,
    fallback: string[],
): ResourceFields {
    const cacheKey = `${tenant}::${resourceKey ?? ''}::${child ?? ''}`;
    const ready = resourceKey !== null && tenant !== '';
    const cached = ready ? discovered.get(cacheKey) : undefined;
    const [, setTick] = useState(0);
    const [failedKey, setFailedKey] = useState<string | null>(null);

    useEffect(() => {
        if (!ready || resourceKey === null || discovered.has(cacheKey)) {
            return;
        }

        let cancelled = false;

        const promise =
            inFlight.get(cacheKey) ??
            probeWithRetry(cacheKey, tenant, resourceKey, child);
        inFlight.set(cacheKey, promise);

        void promise.then((entry) => {
            if (cancelled) {
                return;
            }

            if (entry === null) {
                setFailedKey(cacheKey);
            } else {
                setTick((t) => t + 1);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [cacheKey, ready, resourceKey, tenant, child]);

    if (!ready) {
        return { fields: fallback, source: 'catalog', loading: false };
    }

    if (cached !== undefined) {
        return {
            fields: cached.fields,
            source: cached.source,
            loading: false,
        };
    }

    if (failedKey === cacheKey) {
        return { fields: fallback, source: 'catalog', loading: false };
    }

    return { fields: fallback, source: 'catalog', loading: true };
}
