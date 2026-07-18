import { useCallback, useEffect, useRef, useState } from 'react';
import type { QueryResult } from '@/components/queries/query-result';
import { readCsrfToken } from '@/lib/csrf';
import { readError } from '@/lib/query-spec';
import queries from '@/routes/queries';

/**
 * Spécification sérialisable de l'aperçu live (payload direct-preview).
 *
 * La sélection de colonnes (`fields`/`childFields`) est volontairement absente :
 * elle ne change que la projection, appliquée côté client sur les lignes déjà
 * reçues. Oracle n'est donc resollicité que pour un changement de données
 * (ressource, tenant, filtre, tri, enfants, jointures, limite).
 */
export type LivePreviewSpec = {
    resourceKey: string | null;
    tenant: string;
    expand: string[];
    joins: string[];
    filterQ: string;
    orderBy: string;
    limit: number;
};

export type LivePreview = {
    /** Dernier résultat réussi pour cette ressource (conservé pendant les rafraîchissements). */
    result: QueryResult | null;
    /** Erreur de la tentative correspondant à la spec courante, sinon null. */
    error: string | null;
    /** Vrai pendant le debounce et l'appel réseau. */
    loading: boolean;
    /** Vrai quand `result` correspond exactement à la spec courante. */
    isCurrent: boolean;
    /** Relance immédiate, ex. après un timeout ou un throttle. */
    refresh: () => void;
};

type Success = { key: string; resourceKey: string; result: QueryResult };
type Failure = { key: string; message: string };

const DEBOUNCE_MS = 600;

/**
 * Aperçu live du query builder. Aucun appel Oracle tant que `enabled` est faux :
 * l'aperçu démarre au premier clic sur « Visualiser », puis toute modification
 * de la spec (données uniquement) relance le POST direct-preview ~600 ms après
 * la dernière frappe. La requête en vol est annulée (AbortController) et le
 * dernier résultat réussi de la ressource reste affiché pendant le rafraîchissement.
 */
export function useLivePreview(
    spec: LivePreviewSpec,
    enabled = true,
): LivePreview {
    const [success, setSuccess] = useState<Success | null>(null);
    const [failure, setFailure] = useState<Failure | null>(null);
    const [refreshTick, setRefreshTick] = useState(0);

    const abortRef = useRef<AbortController | null>(null);

    const specKey = JSON.stringify(spec);
    const ready = enabled && spec.resourceKey !== null && spec.tenant !== '';

    const refresh = useCallback(() => {
        setFailure(null);
        setRefreshTick((t) => t + 1);
    }, []);

    useEffect(() => {
        if (!ready) {
            return;
        }

        const timer = setTimeout(async () => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            const current = JSON.parse(specKey) as LivePreviewSpec;

            try {
                const res = await fetch(queries.directPreview.url(), {
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
                        resource_key: current.resourceKey,
                        tenant: current.tenant,
                        expand: current.expand,
                        joins: current.joins,
                        filter_q: current.filterQ.trim() || undefined,
                        order_by: current.orderBy.trim() || undefined,
                        limit: current.limit,
                    }),
                });

                const data = (await res
                    .json()
                    .catch(() => null)) as QueryResult | null;

                if (controller.signal.aborted) {
                    return;
                }

                if (res.status === 429) {
                    setFailure({
                        key: specKey,
                        message:
                            'Trop de rafraîchissements — patientez quelques secondes.',
                    });
                } else if (!res.ok || data === null) {
                    setFailure({ key: specKey, message: readError(data) });
                } else if (data.error) {
                    setFailure({ key: specKey, message: data.error });
                } else {
                    setSuccess({
                        key: specKey,
                        resourceKey: current.resourceKey ?? '',
                        result: data,
                    });
                }
            } catch (e) {
                if (!(e instanceof DOMException && e.name === 'AbortError')) {
                    setFailure({
                        key: specKey,
                        message:
                            "Erreur réseau lors de la préparation de l'aperçu.",
                    });
                }
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [specKey, ready, refreshTick]);

    // Annule la requête en vol au démontage.
    useEffect(() => () => abortRef.current?.abort(), []);

    // Le résultat affiché reste celui de la ressource courante (jamais celui
    // d'une ressource précédente) ; l'erreur ne vaut que pour la spec courante.
    const result =
        ready && success !== null && success.resourceKey === spec.resourceKey
            ? success.result
            : null;
    const error =
        ready && failure !== null && failure.key === specKey
            ? failure.message
            : null;
    const settled = success?.key === specKey || failure?.key === specKey;

    return {
        result,
        error,
        loading: ready && !settled,
        isCurrent: ready && success !== null && success.key === specKey,
        refresh,
    };
}
