import { CheckCircle2, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Input } from '@/components/ui/input';
import { domainColor, domainIcon } from '@/lib/query-spec';
import type { ResourceSuggestion } from '@/lib/query-spec';

/**
 * Grille de sélection de la ressource Oracle (recherche + filtre par domaine).
 * Affichée en pleine largeur tant qu'aucune ressource n'est choisie ; la
 * sélection bascule le builder en mode deux colonnes avec aperçu live.
 */
export function ResourcePicker({
    resources,
    selected,
    onSelect,
}: {
    resources: ResourceSuggestion[];
    selected: ResourceSuggestion | null;
    onSelect: (r: ResourceSuggestion) => void;
}) {
    const [search, setSearch] = useState('');
    const [activeDomain, setActiveDomain] = useState<string | null>(null);

    const domains = useMemo(
        () => [...new Set(resources.map((r) => r.domain))],
        [resources],
    );

    const filtered = useMemo(() => {
        const q = search.toLowerCase().trim();
        let list = resources;

        if (activeDomain) {
            list = list.filter((r) => r.domain === activeDomain);
        }

        if (!q) {
            return list;
        }

        return list.filter(
            (r) =>
                r.label.toLowerCase().includes(q) ||
                r.description.toLowerCase().includes(q) ||
                r.keywords.some((k) => k.toLowerCase().includes(q)),
        );
    }, [resources, search, activeDomain]);

    return (
        <div className="card">
            <div className="card-header">
                <div>
                    <h2 className="card-title">Source de données Oracle</h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Choisissez le jeu de données principal : l'aperçu se
                        charge immédiatement, puis s'adapte en direct à chaque
                        ajustement (colonnes, données liées, filtres…).
                    </p>
                </div>
            </div>

            <div className="card-body space-y-5">
                {/* Recherche */}
                <div className="relative">
                    <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        className="h-10 pl-9"
                        placeholder="Rechercher : fournisseur, facture, employé, bon de commande…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        autoFocus
                    />
                </div>

                {/* Filtres par domaine */}
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => setActiveDomain(null)}
                        className={[
                            'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            !activeDomain
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground hover:border-primary/50',
                        ].join(' ')}
                    >
                        Tous
                    </button>
                    {domains.map((d) => (
                        <button
                            key={d}
                            type="button"
                            onClick={() =>
                                setActiveDomain(activeDomain === d ? null : d)
                            }
                            className={[
                                'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                                activeDomain === d
                                    ? domainColor(d)
                                    : 'border-border text-muted-foreground hover:border-primary/50',
                            ].join(' ')}
                        >
                            {domainIcon(d)} {d}
                        </button>
                    ))}
                </div>

                {/* Grille de cards */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filtered.map((r) => {
                        const isSelected = selected?.key === r.key;

                        return (
                            <button
                                key={r.key}
                                type="button"
                                onClick={() => onSelect(r)}
                                className={[
                                    'group flex flex-col gap-3 rounded-xl border p-4 text-left transition-all duration-150',
                                    isSelected
                                        ? 'border-primary bg-primary/5 shadow-sm ring-2 ring-primary/30'
                                        : 'border-border bg-card hover:border-primary/50 hover:shadow-sm',
                                ].join(' ')}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className="text-lg leading-none">
                                            {domainIcon(r.domain)}
                                        </span>
                                        <span className="text-sm leading-snug font-semibold">
                                            {r.label}
                                        </span>
                                    </div>
                                    <span
                                        className={`shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium ${domainColor(r.domain)}`}
                                    >
                                        {r.domain}
                                    </span>
                                </div>

                                <p className="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                    {r.description}
                                </p>

                                {(r.fields ?? []).length > 0 && (
                                    <div className="flex flex-wrap gap-1">
                                        {(r.fields ?? [])
                                            .slice(0, 4)
                                            .map((f) => (
                                                <span
                                                    key={f}
                                                    className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                                >
                                                    {f}
                                                </span>
                                            ))}
                                        {(r.fields ?? []).length > 4 && (
                                            <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                                +{(r.fields ?? []).length - 4}{' '}
                                                champs
                                            </span>
                                        )}
                                    </div>
                                )}

                                {((r.child_resources ?? []).length > 0 ||
                                    Object.keys(r.join_keys ?? {}).length >
                                        0) && (
                                    <div className="flex flex-wrap gap-1 border-t pt-2">
                                        {(r.child_resources ?? [])
                                            .slice(0, 2)
                                            .map((c) => (
                                                <span
                                                    key={c}
                                                    className="rounded border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300"
                                                >
                                                    ↳ {c}
                                                </span>
                                            ))}
                                        {Object.keys(r.join_keys ?? {})
                                            .slice(0, 2)
                                            .map((target) => (
                                                <span
                                                    key={target}
                                                    className="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                >
                                                    ⟷ {target}
                                                </span>
                                            ))}
                                    </div>
                                )}

                                {isSelected && (
                                    <div className="flex items-center gap-1.5 text-xs font-medium text-primary">
                                        <CheckCircle2 className="size-3.5" />
                                        Sélectionné
                                    </div>
                                )}
                            </button>
                        );
                    })}

                    {filtered.length === 0 && (
                        <div className="col-span-full rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                            Aucune ressource ne correspond à « {search} »
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
