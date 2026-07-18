import {
    ChevronDown,
    ChevronRight,
    Filter,
    Link2,
    Plus,
    RefreshCcw,
    Search,
    SortAsc,
    Table2,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useResourceFields } from '@/hooks/use-resource-fields';
import { useI18n } from '@/i18n/i18n-context';
import {
    FILTER_OPERATORS,
    domainIcon,
    filterRowsToQ,
    newFilterRow,
    parseLimit,
} from '@/lib/query-spec';
import type {
    ChildFieldsMap,
    FilterRow,
    ResourceSuggestion,
} from '@/lib/query-spec';

// ─── Pill de champ ─────────────────────────────────────────────────────────────

function FieldPill({
    label,
    checked,
    onClick,
    variant = 'field',
}: {
    label: string;
    checked: boolean;
    onClick: () => void;
    variant?: 'field' | 'child' | 'join';
}) {
    const base =
        'inline-flex cursor-pointer items-center gap-1 rounded-lg border px-2.5 py-1 font-mono text-xs transition-all select-none';
    const colors = {
        field: checked
            ? 'border-primary bg-primary text-primary-foreground shadow-sm'
            : 'border-border bg-background text-muted-foreground hover:border-primary/60 hover:text-foreground',
        child: checked
            ? 'border-blue-500 bg-blue-500 text-white shadow-sm'
            : 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 hover:border-blue-400',
        join: checked
            ? 'border-emerald-500 bg-emerald-500 text-white shadow-sm'
            : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 hover:border-emerald-400',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            className={`${base} ${colors[variant]}`}
        >
            {variant === 'child' && (
                <ChevronRight className="size-3 opacity-60" />
            )}
            {variant === 'join' && <Link2 className="size-3 opacity-60" />}
            {label}
        </button>
    );
}

// ─── Liste de champs avec recherche ──────────────────────────────────────────

/**
 * Sélection de colonnes compacte : seules les colonnes choisies restent dans
 * le panneau (cliquer = retirer) ; la liste complète découverte sur le tenant,
 * avec recherche, vit dans une boîte de dialogue.
 */
function FieldPickList({
    fields,
    loading,
    source,
    selected,
    onToggle,
    onClear,
    variant,
    title,
    emptyHint,
}: {
    fields: string[];
    loading: boolean;
    source: 'live' | 'catalog';
    selected: string[];
    onToggle: (field: string) => void;
    onClear: () => void;
    variant: 'field' | 'child' | 'join';
    title: string;
    emptyHint: string;
}) {
    const [query, setQuery] = useState('');
    // Une sélection enregistrée reste visible même hors liste découverte.
    const all = useMemo(
        () => Array.from(new Set([...fields, ...selected])),
        [fields, selected],
    );
    const needle = query.trim().toLowerCase();
    const visible =
        needle === ''
            ? all
            : all.filter((f) => f.toLowerCase().includes(needle));

    return (
        <div className="flex flex-col gap-2">
            {selected.length > 0 ? (
                <div className="flex flex-wrap gap-1.5">
                    {selected.map((f) => (
                        <FieldPill
                            key={f}
                            label={f}
                            checked
                            onClick={() => onToggle(f)}
                            variant={variant}
                        />
                    ))}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground italic">
                    {emptyHint}
                </p>
            )}

            <Dialog
                onOpenChange={(open) => {
                    if (!open) {
                        setQuery('');
                    }
                }}
            >
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="self-start"
                    >
                        <Plus className="size-3.5" />
                        Choisir les colonnes
                    </Button>
                </DialogTrigger>
                <DialogContent className="flex max-h-[85vh] flex-col sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>
                            {selected.length === 0
                                ? emptyHint
                                : `${selected.length} colonne(s) sélectionnée(s) — cliquez pour ajouter ou retirer.`}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Rechercher un champ…"
                            className="h-9 pl-9"
                        />
                    </div>

                    {loading && (
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Spinner className="size-3" />
                            Lecture des champs sur l'environnement…
                        </p>
                    )}

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <div className="flex flex-wrap gap-1.5 py-1">
                            {visible.map((f) => (
                                <FieldPill
                                    key={f}
                                    label={f}
                                    checked={selected.includes(f)}
                                    onClick={() => onToggle(f)}
                                    variant={variant}
                                />
                            ))}
                        </div>
                        {visible.length === 0 && needle !== '' && (
                            <p className="text-xs text-muted-foreground italic">
                                Aucun champ ne correspond à «{query}».
                            </p>
                        )}
                        {!loading && all.length === 0 && (
                            <p className="text-xs text-muted-foreground italic">
                                {emptyHint}
                            </p>
                        )}
                    </div>

                    {!loading && source === 'live' && (
                        <p className="text-[11px] text-muted-foreground">
                            Champs lus depuis l'environnement sélectionné.
                        </p>
                    )}

                    <DialogFooter className="gap-2">
                        {selected.length > 0 && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onClear}
                            >
                                Tout effacer
                            </Button>
                        )}
                        <DialogClose asChild>
                            <Button type="button">Terminé</Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** FieldPickList qui sonde lui-même le tenant (enfants expand et jointures). */
function DiscoveredFieldPick({
    resourceKey,
    tenant,
    child,
    fallback,
    selected,
    onToggle,
    onClear,
    variant,
    title,
    emptyHint,
}: {
    resourceKey: string;
    tenant: string;
    child: string | null;
    fallback: string[];
    selected: string[];
    onToggle: (field: string) => void;
    onClear: () => void;
    variant: 'field' | 'child' | 'join';
    title: string;
    emptyHint: string;
}) {
    const discovery = useResourceFields(resourceKey, tenant, child, fallback);

    return (
        <FieldPickList
            fields={discovery.fields}
            loading={discovery.loading}
            source={discovery.source}
            selected={selected}
            onToggle={onToggle}
            onClear={onClear}
            variant={variant}
            title={title}
            emptyHint={emptyHint}
        />
    );
}

// ─── Section repliable ────────────────────────────────────────────────────────

function Collapsible({
    title,
    subtitle,
    icon,
    defaultOpen = false,
    badge,
    children,
}: {
    title: string;
    subtitle?: string;
    icon: React.ReactNode;
    defaultOpen?: boolean;
    badge?: number;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40"
            >
                <span className="text-muted-foreground">{icon}</span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">{title}</span>
                        {badge !== undefined && badge > 0 && (
                            <span className="inline-flex items-center rounded-full bg-primary/10 px-1.5 py-0.5 text-xs font-semibold text-primary">
                                {badge}
                            </span>
                        )}
                    </div>
                    {subtitle && (
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    )}
                </div>
                <ChevronDown
                    className={`size-4 text-muted-foreground transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && (
                <div className="border-t bg-muted/10 px-4 py-4">{children}</div>
            )}
        </div>
    );
}

// ─── Constructeur de filtres assisté ─────────────────────────────────────────

function FilterBuilder({
    rows,
    parentFields,
    onChange,
}: {
    rows: FilterRow[];
    parentFields: string[];
    onChange: (rows: FilterRow[]) => void;
}) {
    function update(id: string, patch: Partial<FilterRow>) {
        onChange(rows.map((r) => (r.id === id ? { ...r, ...patch } : r)));
    }

    function remove(id: string) {
        onChange(rows.filter((r) => r.id !== id));
    }

    function add() {
        onChange([...rows, newFilterRow()]);
    }

    return (
        <div className="flex flex-col gap-2">
            {rows.length === 0 && (
                <p className="text-xs text-muted-foreground italic">
                    Aucun filtre — tous les enregistrements seront retournés.
                </p>
            )}

            {rows.map((row, idx) => (
                <div key={row.id} className="flex flex-wrap items-center gap-2">
                    {/* Conjonction AND/OR (sauf première ligne) */}
                    {idx > 0 ? (
                        <Select
                            value={row.conjunction}
                            onValueChange={(v) =>
                                update(row.id, {
                                    conjunction: v as 'AND' | 'OR',
                                })
                            }
                        >
                            <SelectTrigger className="h-8 w-16 text-xs font-semibold">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="AND">ET</SelectItem>
                                <SelectItem value="OR">OU</SelectItem>
                            </SelectContent>
                        </Select>
                    ) : (
                        <span className="flex h-8 w-16 items-center justify-center rounded-md border border-transparent text-xs font-semibold text-muted-foreground">
                            OÙ
                        </span>
                    )}

                    {/* Champ */}
                    <Select
                        value={row.field}
                        onValueChange={(v) => update(row.id, { field: v })}
                    >
                        <SelectTrigger className="h-8 min-w-36 flex-1 font-mono text-xs">
                            <SelectValue placeholder="Champ…" />
                        </SelectTrigger>
                        <SelectContent>
                            {parentFields.map((f) => (
                                <SelectItem
                                    key={f}
                                    value={f}
                                    className="font-mono text-xs"
                                >
                                    {f}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {/* Opérateur */}
                    <Select
                        value={row.operator}
                        onValueChange={(v) => update(row.id, { operator: v })}
                    >
                        <SelectTrigger className="h-8 w-44 text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {FILTER_OPERATORS.map((op) => (
                                <SelectItem
                                    key={op.value}
                                    value={op.value}
                                    className="text-xs"
                                >
                                    {op.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {/* Valeur */}
                    <Input
                        className="h-8 min-w-32 flex-1 font-mono text-xs"
                        placeholder={
                            row.operator === 'LIKE' ||
                            row.operator === 'STARTSWITH'
                                ? 'ex : Acme'
                                : 'valeur…'
                        }
                        value={row.value}
                        onChange={(e) =>
                            update(row.id, { value: e.target.value })
                        }
                    />

                    {/* Supprimer */}
                    <button
                        type="button"
                        onClick={() => remove(row.id)}
                        className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                        title="Supprimer ce filtre"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            ))}

            <button
                type="button"
                onClick={add}
                className="mt-1 flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
            >
                <Plus className="size-3.5" />
                Ajouter un filtre
            </button>

            {/* Aperçu de la syntaxe générée */}
            {rows.some((r) => r.field && r.value) && (
                <div className="mt-2 rounded-md border bg-muted/40 px-3 py-2">
                    <p className="mb-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                        Syntaxe Oracle REST générée
                    </p>
                    <code className="font-mono text-xs text-foreground">
                        {filterRowsToQ(rows, parentFields) || '…'}
                    </code>
                </div>
            )}
        </div>
    );
}

// ─── Panneau de configuration (colonne gauche du builder) ────────────────────

export function QueryConfigPanel({
    resource,
    allResources,
    tenants,
    fields,
    setFields,
    expand,
    setExpand,
    joins,
    setJoins,
    childFields,
    setChildFields,
    filterRows,
    setFilterRows,
    orderBy,
    setOrderBy,
    tenant,
    setTenant,
    limit,
    setLimit,
    onChangeResource,
}: {
    resource: ResourceSuggestion;
    allResources: ResourceSuggestion[];
    tenants: Record<string, string>;
    fields: string[];
    setFields: (v: string[]) => void;
    expand: string[];
    setExpand: (v: string[]) => void;
    joins: string[];
    setJoins: (v: string[]) => void;
    childFields: ChildFieldsMap;
    setChildFields: (v: ChildFieldsMap) => void;
    filterRows: FilterRow[];
    setFilterRows: (v: FilterRow[]) => void;
    orderBy: string;
    setOrderBy: (v: string) => void;
    tenant: string;
    setTenant: (v: string) => void;
    limit: string;
    setLimit: (v: string) => void;
    onChangeResource: () => void;
}) {
    const { t } = useI18n();
    const parentDiscovery = useResourceFields(
        resource.key,
        tenant,
        null,
        resource.fields ?? [],
    );
    // Champs proposés aux filtres/tri : découverte tenant + sélections déjà
    // enregistrées (une requête existante peut référencer un champ non sondé).
    const allFields = useMemo(
        () =>
            Array.from(
                new Set([
                    ...parentDiscovery.fields,
                    ...fields,
                    ...filterRows
                        .map((row) => row.field)
                        .filter((field) => field !== ''),
                ]),
            ),
        [parentDiscovery.fields, fields, filterRows],
    );
    const childResources = useMemo(
        () => resource.child_resources ?? [],
        [resource.child_resources],
    );
    const joinKeysDefs = resource.join_keys ?? {};
    const joinTargets = Object.keys(joinKeysDefs);

    // Ressources joignables — on affiche leur label enrichi
    const joinableResources = allResources.filter((r) =>
        joinTargets.includes(r.key),
    );

    // Catalogue des champs enfants :
    // • enfants imbriqués (expand) → child_fields du catalogue
    // • ressources joignables       → leurs champs top-level
    const childFieldsCatalog = useMemo<ChildFieldsMap>(() => {
        const map: ChildFieldsMap = {};

        const catalogChildFields = resource.child_fields ?? {};

        for (const c of childResources) {
            map[c] = catalogChildFields[c] ?? [];
        }

        for (const jr of joinableResources) {
            map[jr.key] = jr.fields ?? [];
        }

        return map;
    }, [resource.child_fields, childResources, joinableResources]);

    function toggle(list: string[], item: string, set: (v: string[]) => void) {
        set(
            list.includes(item)
                ? list.filter((x) => x !== item)
                : [...list, item],
        );
    }

    function toggleChildField(childKey: string, field: string) {
        const current = childFields[childKey] ?? [];
        const updated = current.includes(field)
            ? current.filter((f) => f !== field)
            : [...current, field];

        setChildFields({ ...childFields, [childKey]: updated });
    }

    function dropChildFields(key: string) {
        const updated = { ...childFields };
        delete updated[key];
        setChildFields(updated);
    }

    // Enfants Oracle imbriqués → paramètre REST `expand` (un seul GET)
    function toggleExpand(key: string) {
        if (expand.includes(key)) {
            setExpand(expand.filter((x) => x !== key));
            dropChildFields(key);
        } else {
            setExpand([...expand, key]);
        }
    }

    // Ressources de premier niveau reliées par une clé → second GET joint côté serveur
    function toggleJoin(key: string) {
        if (joins.includes(key)) {
            setJoins(joins.filter((x) => x !== key));
            dropChildFields(key);
        } else {
            setJoins([...joins, key]);
        }
    }

    const hasRelated = childResources.length > 0 || joinTargets.length > 0;
    const activeFilterCount = filterRows.filter(
        (r) => r.field && r.value.trim(),
    ).length;

    return (
        <div className="flex flex-col gap-4">
            {/* ─ Ressource choisie ─ */}
            <div className="flex items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="text-lg leading-none">
                        {domainIcon(resource.domain)}
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold">
                            {resource.label}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {resource.path}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onChangeResource}
                    className="flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground"
                >
                    <RefreshCcw className="size-3.5" />
                    Changer
                </button>
            </div>

            {/* ─ Champs principaux ─ */}
            <Collapsible
                title="Colonnes à inclure"
                subtitle={
                    fields.length === 0
                        ? 'Toutes les colonnes (par défaut)'
                        : `${fields.length} colonne(s) sélectionnée(s)`
                }
                icon={<Table2 className="size-4" />}
                badge={fields.length}
                defaultOpen
            >
                <FieldPickList
                    fields={parentDiscovery.fields}
                    loading={parentDiscovery.loading}
                    source={parentDiscovery.source}
                    selected={fields}
                    onToggle={(f) => toggle(fields, f, setFields)}
                    onClear={() => setFields([])}
                    variant="field"
                    title={`Colonnes de «${resource.label}»`}
                    emptyHint="Sans sélection → toutes les colonnes sont renvoyées."
                />
            </Collapsible>

            {/* ─ Données liées ─ */}
            {hasRelated && (
                <Collapsible
                    title="Données liées"
                    subtitle="Enfants (expand) et jointures — choisissez aussi leurs colonnes"
                    icon={<Link2 className="size-4" />}
                    badge={expand.length + joins.length}
                >
                    {childResources.length > 0 && (
                        <div className="mb-5">
                            <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Enfants imbriqués (expand)
                            </p>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Ex : fournisseurs{' '}
                                <strong>avec leurs sites</strong> ou{' '}
                                <strong>leurs contacts</strong> dans le même
                                résultat.
                            </p>
                            <div className="mb-3 flex flex-wrap gap-2">
                                {childResources.map((c) => (
                                    <FieldPill
                                        key={c}
                                        label={c}
                                        checked={expand.includes(c)}
                                        onClick={() => toggleExpand(c)}
                                        variant="child"
                                    />
                                ))}
                            </div>

                            {/* Sélection des champs de chaque enfant activé */}
                            {childResources
                                .filter((c) => expand.includes(c))
                                .map((c) => (
                                    <div
                                        key={c}
                                        className="mb-3 rounded-lg border border-blue-200 bg-blue-50/40 p-3 dark:border-blue-800 dark:bg-blue-950/20"
                                    >
                                        <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-blue-700 dark:text-blue-300">
                                            <ChevronRight className="size-3.5" />
                                            Colonnes de «{c}» à inclure
                                        </p>
                                        <DiscoveredFieldPick
                                            resourceKey={resource.key}
                                            tenant={tenant}
                                            child={c}
                                            fallback={
                                                childFieldsCatalog[c] ?? []
                                            }
                                            selected={childFields[c] ?? []}
                                            onToggle={(f) =>
                                                toggleChildField(c, f)
                                            }
                                            onClear={() =>
                                                setChildFields({
                                                    ...childFields,
                                                    [c]: [],
                                                })
                                            }
                                            variant="child"
                                            title={`Colonnes de «${c}»`}
                                            emptyHint="Sans sélection → tous les champs de l'enfant sont inclus."
                                        />
                                    </div>
                                ))}
                        </div>
                    )}

                    {joinableResources.length > 0 && (
                        <div>
                            <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Jointures avec d'autres ressources
                            </p>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Enrichissez les résultats avec des données d'une
                                ressource liée.
                            </p>
                            <div className="mb-3 flex flex-wrap gap-2">
                                {joinableResources.map((r) => {
                                    const joinDef = joinKeysDefs[r.key];

                                    return (
                                        <FieldPill
                                            key={r.key}
                                            label={joinDef?.label ?? r.label}
                                            checked={joins.includes(r.key)}
                                            onClick={() => toggleJoin(r.key)}
                                            variant="join"
                                        />
                                    );
                                })}
                            </div>

                            {/* Sélection des champs de chaque jointure activée */}
                            {joinableResources
                                .filter((r) => joins.includes(r.key))
                                .map((r) => {
                                    const joinDef = joinKeysDefs[r.key];

                                    return (
                                        <div
                                            key={r.key}
                                            className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/40 p-3 dark:border-emerald-800 dark:bg-emerald-950/20"
                                        >
                                            <div className="mb-2 flex items-start justify-between gap-2">
                                                <p className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                                    <Link2 className="size-3.5 shrink-0" />
                                                    {joinDef?.label ?? r.label}
                                                </p>
                                                {joinDef && (
                                                    <span className="shrink-0 rounded border border-emerald-200 bg-emerald-100/60 px-1.5 py-0.5 font-mono text-[10px] text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                        via {joinDef.local_key}
                                                    </span>
                                                )}
                                            </div>
                                            <DiscoveredFieldPick
                                                resourceKey={r.key}
                                                tenant={tenant}
                                                child={null}
                                                fallback={
                                                    childFieldsCatalog[r.key] ??
                                                    []
                                                }
                                                selected={
                                                    childFields[r.key] ?? []
                                                }
                                                onToggle={(f) =>
                                                    toggleChildField(r.key, f)
                                                }
                                                onClear={() =>
                                                    setChildFields({
                                                        ...childFields,
                                                        [r.key]: [],
                                                    })
                                                }
                                                variant="join"
                                                title={`Colonnes de «${joinDef?.label ?? r.label}»`}
                                                emptyHint="Sans sélection → tous les champs de la ressource liée sont inclus."
                                            />
                                        </div>
                                    );
                                })}
                        </div>
                    )}
                </Collapsible>
            )}

            {/* ─ Filtres assistés ─ */}
            <Collapsible
                title="Filtres"
                subtitle={
                    activeFilterCount === 0
                        ? 'Optionnel — chercher un fournisseur, une facture, par nom/numéro/statut…'
                        : `${activeFilterCount} filtre(s) actif(s)`
                }
                icon={<Filter className="size-4" />}
                badge={activeFilterCount}
                defaultOpen={filterRows.length > 0}
            >
                <FilterBuilder
                    rows={filterRows}
                    parentFields={allFields}
                    onChange={setFilterRows}
                />
            </Collapsible>

            {/* ─ Tri ─ */}
            <Collapsible
                title="Tri"
                subtitle="Optionnel — ordonner les résultats"
                icon={<SortAsc className="size-4" />}
                badge={orderBy.trim() ? 1 : 0}
            >
                <div className="flex flex-col gap-1.5">
                    <div className="flex flex-wrap gap-2">
                        {allFields.slice(0, 6).map((f) => (
                            <button
                                key={f}
                                type="button"
                                onClick={() => {
                                    if (orderBy === `${f}:asc`) {
                                        setOrderBy(`${f}:desc`);
                                    } else if (orderBy === `${f}:desc`) {
                                        setOrderBy('');
                                    } else {
                                        setOrderBy(`${f}:asc`);
                                    }
                                }}
                                className={[
                                    'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 font-mono text-xs transition-all',
                                    orderBy.startsWith(f)
                                        ? 'border-primary bg-primary/10 font-semibold text-primary'
                                        : 'border-border text-muted-foreground hover:border-primary/50',
                                ].join(' ')}
                            >
                                {f}
                                {orderBy === `${f}:asc` && ' ↑'}
                                {orderBy === `${f}:desc` && ' ↓'}
                            </button>
                        ))}
                    </div>
                    <Label
                        htmlFor="qb-order"
                        className="mt-2 flex items-center gap-1.5 text-xs font-medium"
                    >
                        <SortAsc className="size-3.5 text-muted-foreground" />
                        Valeur libre (orderBy=)
                    </Label>
                    <Input
                        id="qb-order"
                        value={orderBy}
                        onChange={(e) => setOrderBy(e.target.value)}
                        placeholder={`Ex : ${allFields[0] ?? 'CreationDate'}:desc`}
                        className="font-mono text-xs"
                    />
                </div>
            </Collapsible>

            {/* ─ Environnement & limite ─ */}
            <div className="flex flex-wrap gap-3 rounded-xl border bg-card p-4">
                <div className="flex max-w-32 flex-col gap-1.5">
                    <Label htmlFor="qb-limit" className="text-xs font-medium">
                        Limite de lignes
                    </Label>
                    <Input
                        id="qb-limit"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={500}
                        value={limit}
                        onChange={(e) => setLimit(e.target.value)}
                        onBlur={() => setLimit(String(parseLimit(limit)))}
                        className="h-9"
                    />
                </div>

                <div className="flex min-w-44 flex-1 flex-col gap-1.5">
                    <Label htmlFor="qb-tenant" className="text-xs font-medium">
                        {t('queries.environment')}
                    </Label>
                    <Select
                        value={tenant}
                        onValueChange={setTenant}
                        disabled={Object.keys(tenants).length === 0}
                    >
                        <SelectTrigger id="qb-tenant" className="h-9">
                            <SelectValue
                                placeholder={t('queries.chooseEnvironment')}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(tenants).map(([k, v]) => (
                                <SelectItem key={k} value={k}>
                                    {v}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <Badge
                variant="secondary"
                className="self-start text-[11px] font-normal text-muted-foreground"
            >
                L'aperçu se met à jour automatiquement à chaque modification.
            </Badge>
        </div>
    );
}
