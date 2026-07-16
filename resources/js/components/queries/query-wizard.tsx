import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Braces,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Code2,
    Eye,
    Filter,
    Globe,
    Link2,
    ListFilter,
    Lock,
    Save,
    Search,
    SortAsc,
    Table2,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ResourceSuggestion } from '@/components/queries/query-form';
import { QueryResultView } from '@/components/queries/query-result';
import type { QueryResult } from '@/components/queries/query-result';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

// ─── Types ────────────────────────────────────────────────────────────────────

type Step = 1 | 2 | 3;

// Étend ResourceSuggestion pour inclure join_keys
type Resource = ResourceSuggestion & { join_keys?: Record<string, string> };

type WizardProps = {
    resourceSuggestions: Resource[];
    tenants: Record<string, string>;
    defaultTenant: string;
};

// ─── Constantes ───────────────────────────────────────────────────────────────

const DEFAULT_LIMIT = 25;

const DOMAIN_META: Record<string, { color: string; icon: string }> = {
    Procurement: {
        color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800',
        icon: '🛒',
    },
    Finance: {
        color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
        icon: '💰',
    },
    HCM: {
        color: 'bg-violet-50 text-violet-700 border-violet-200 dark:bg-violet-950/60 dark:text-violet-300 dark:border-violet-800',
        icon: '👥',
    },
    Inventory: {
        color: 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/60 dark:text-orange-300 dark:border-orange-800',
        icon: '📦',
    },
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function domainColor(domain: string) {
    return DOMAIN_META[domain]?.color ?? 'bg-muted text-muted-foreground border-border';
}

function domainIcon(domain: string) {
    return DOMAIN_META[domain]?.icon ?? '📋';
}

function parseLimit(v: string): number {
    const n = Number.parseInt(v, 10);

    return Number.isNaN(n) ? DEFAULT_LIMIT : Math.min(500, Math.max(1, n));
}

function isRecord(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null;
}

function readError(data: unknown): string {
    if (isRecord(data)) {
        if (typeof data.message === 'string') {
            return data.message;
        }

        if (typeof data.error === 'string') {
            return data.error;
        }
    }

    return "Impossible de préparer l'aperçu pour le moment.";
}

// Génère un SQL BIP (Oracle BI Publisher) à partir des paramètres du wizard
function generateBipSql(
    resource: Resource,
    fields: string[],
    expand: string[],
    filterQ: string,
    orderBy: string,
    limit: number,
): string {
    const cols = fields.length > 0 ? fields : (resource.fields ?? []);
    const tableName = resource.key.toUpperCase();
    const selectCols = cols.map((f) => `  ${tableName}.${f}`).join(',\n');

    let sql = `-- Requête générée par OracleData Wizard\n`;
    sql += `-- Ressource : ${resource.label} (${resource.domain})\n`;
    sql += `-- Chemin REST : ${resource.path}\n\n`;
    sql += `SELECT\n${selectCols}\nFROM ${tableName}`;

    if (expand.length > 0) {
        expand.forEach((child) => {
            const childTable = child.toUpperCase();
            sql += `\nLEFT JOIN ${tableName}_${childTable} ${childTable}`;
            sql += `\n  ON ${childTable}.PARENT_${tableName}_ID = ${tableName}.${tableName.replace(/S$/, '')}ID`;
        });
    }

    if (filterQ.trim()) {
        sql += `\nWHERE ${filterQ.trim()}`;
    }

    if (orderBy.trim()) {
        sql += `\nORDER BY ${orderBy.trim()}`;
    }

    sql += `\nFETCH FIRST ${limit} ROWS ONLY`;

    return sql;
}

// ─── Barre de progression ─────────────────────────────────────────────────────

function StepBar({ step }: { step: Step }) {
    const steps = [
        { num: 1 as const, label: 'Source de données' },
        { num: 2 as const, label: 'Données & filtres' },
        { num: 3 as const, label: 'Aperçu & enregistrement' },
    ];

    return (
        <nav aria-label="Étapes du wizard" className="mb-8">
            <ol className="flex items-start gap-0">
                {steps.map((s, i) => (
                    <li key={s.num} className="flex flex-1 items-start">
                        <div className="flex flex-col items-center gap-1.5">
                            <div
                                aria-current={step === s.num ? 'step' : undefined}
                                className={[
                                    'flex size-9 items-center justify-center rounded-full text-sm font-bold border-2 transition-all duration-200',
                                    step > s.num
                                        ? 'bg-primary border-primary text-primary-foreground shadow-sm'
                                        : step === s.num
                                          ? 'border-primary text-primary bg-primary/5'
                                          : 'border-border text-muted-foreground bg-background',
                                ].join(' ')}
                            >
                                {step > s.num ? <CheckCircle2 className="size-4" /> : s.num}
                            </div>
                            <span
                                className={[
                                    'hidden text-center text-xs font-medium leading-tight sm:block',
                                    step === s.num ? 'text-primary' : 'text-muted-foreground',
                                ].join(' ')}
                                style={{ maxWidth: '80px' }}
                            >
                                {s.label}
                            </span>
                        </div>
                        {i < steps.length - 1 && (
                            <div
                                className={[
                                    'mx-1.5 mt-4 h-0.5 flex-1 transition-colors duration-300',
                                    step > s.num ? 'bg-primary' : 'bg-border',
                                ].join(' ')}
                            />
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

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
        <button type="button" onClick={onClick} className={`${base} ${colors[variant]}`}>
            {variant === 'child' && <ChevronRight className="size-3 opacity-60" />}
            {variant === 'join' && <Link2 className="size-3 opacity-60" />}
            {label}
        </button>
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
        <div className="rounded-xl border bg-card overflow-hidden">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/40 transition-colors"
            >
                <span className="text-muted-foreground">{icon}</span>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">{title}</span>
                        {badge !== undefined && badge > 0 && (
                            <span className="inline-flex items-center rounded-full bg-primary/10 px-1.5 py-0.5 text-xs font-semibold text-primary">
                                {badge}
                            </span>
                        )}
                    </div>
                    {subtitle && (
                        <p className="mt-0.5 text-xs text-muted-foreground truncate">{subtitle}</p>
                    )}
                </div>
                <ChevronDown
                    className={`size-4 text-muted-foreground transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && <div className="border-t bg-muted/10 px-4 py-4">{children}</div>}
        </div>
    );
}

// ─── ÉTAPE 1 — Source de données ──────────────────────────────────────────────

function Step1({
    resources,
    selected,
    onSelect,
    onNext,
}: {
    resources: Resource[];
    selected: Resource | null;
    onSelect: (r: Resource) => void;
    onNext: () => void;
}) {
    const [search, setSearch] = useState('');
    const [activeDomain, setActiveDomain] = useState<string | null>(null);

    const domains = useMemo(() => [...new Set(resources.map((r) => r.domain))], [resources]);

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
        <div className="flex flex-col gap-6">
            <div>
                <h2 className="text-lg font-semibold tracking-tight">Source de données Oracle</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Choisissez le jeu de données principal à interroger. Vous pourrez enrichir avec des données liées à l'étape suivante.
                </p>
            </div>

            {/* Recherche */}
            <div className="relative">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    className="pl-9 h-10"
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
                        onClick={() => setActiveDomain(activeDomain === d ? null : d)}
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
            <div className="grid gap-3 sm:grid-cols-2">
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
                                    ? 'border-primary bg-primary/5 ring-2 ring-primary/30 shadow-sm'
                                    : 'border-border bg-card hover:border-primary/50 hover:shadow-sm',
                            ].join(' ')}
                        >
                            {/* Header */}
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span className="text-lg leading-none">{domainIcon(r.domain)}</span>
                                    <span className="font-semibold text-sm leading-snug">{r.label}</span>
                                </div>
                                <span className={`shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium ${domainColor(r.domain)}`}>
                                    {r.domain}
                                </span>
                            </div>

                            {/* Description */}
                            <p className="text-xs text-muted-foreground leading-relaxed line-clamp-2">
                                {r.description}
                            </p>

                            {/* Champs */}
                            {(r.fields ?? []).length > 0 && (
                                <div className="flex flex-wrap gap-1">
                                    {(r.fields ?? []).slice(0, 4).map((f) => (
                                        <span key={f} className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                            {f}
                                        </span>
                                    ))}
                                    {(r.fields ?? []).length > 4 && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                            +{(r.fields ?? []).length - 4} champs
                                        </span>
                                    )}
                                </div>
                            )}

                            {/* Données liées disponibles */}
                            {((r.child_resources ?? []).length > 0 || Object.keys(r.join_keys ?? {}).length > 0) && (
                                <div className="flex flex-wrap gap-1 border-t pt-2">
                                    {(r.child_resources ?? []).slice(0, 2).map((c) => (
                                        <span key={c} className="rounded border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
                                            ↳ {c}
                                        </span>
                                    ))}
                                    {Object.keys(r.join_keys ?? {}).slice(0, 2).map((target) => (
                                        <span key={target} className="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                            ⟷ {target}
                                        </span>
                                    ))}
                                </div>
                            )}

                            {/* Indicateur sélectionné */}
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
                    <div className="col-span-2 rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        Aucune ressource ne correspond à « {search} »
                    </div>
                )}
            </div>

            <div className="flex items-center justify-between border-t pt-4">
                <p className="text-xs text-muted-foreground">
                    {selected ? (
                        <span className="font-medium text-foreground">
                            {domainIcon(selected.domain)} {selected.label} sélectionné
                        </span>
                    ) : (
                        'Sélectionnez une ressource pour continuer'
                    )}
                </p>
                <Button onClick={onNext} disabled={!selected} size="default">
                    Suivant
                    <ArrowRight className="ml-1 size-4" />
                </Button>
            </div>
        </div>
    );
}

// ─── ÉTAPE 2 — Données & filtres ──────────────────────────────────────────────

function Step2({
    resource,
    allResources,
    tenants,
    fields,
    setFields,
    expand,
    setExpand,
    filterQ,
    setFilterQ,
    orderBy,
    setOrderBy,
    tenant,
    setTenant,
    limit,
    setLimit,
    onBack,
    onNext,
}: {
    resource: Resource;
    allResources: Resource[];
    tenants: Record<string, string>;
    fields: string[];
    setFields: (v: string[]) => void;
    expand: string[];
    setExpand: (v: string[]) => void;
    filterQ: string;
    setFilterQ: (v: string) => void;
    orderBy: string;
    setOrderBy: (v: string) => void;
    tenant: string;
    setTenant: (v: string) => void;
    limit: string;
    setLimit: (v: string) => void;
    onBack: () => void;
    onNext: () => void;
}) {
    const allFields = resource.fields ?? [];
    const childResources = resource.child_resources ?? [];
    const joinKeys = resource.join_keys ?? {};
    const joinTargets = Object.keys(joinKeys);

    // Ressources joignables — on affiche leur label
    const joinableResources = allResources.filter((r) => joinTargets.includes(r.key));

    function toggle(list: string[], item: string, set: (v: string[]) => void) {
        set(list.includes(item) ? list.filter((x) => x !== item) : [...list, item]);
    }

    const canContinue = tenant !== '';
    const hasRelated = childResources.length > 0 || joinTargets.length > 0;

    return (
        <div className="flex flex-col gap-4">
            <div>
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-semibold tracking-tight">Données et filtres</h2>
                    <Badge variant="secondary" className="text-xs">
                        {domainIcon(resource.domain)} {resource.label}
                    </Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                    Choisissez les colonnes, ajoutez des données liées, et affinez avec des filtres.
                </p>
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
                <div className="flex flex-wrap gap-2">
                    {allFields.map((f) => (
                        <FieldPill
                            key={f}
                            label={f}
                            checked={fields.includes(f)}
                            onClick={() => toggle(fields, f, setFields)}
                            variant="field"
                        />
                    ))}
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                    Sans sélection → toutes les colonnes sont renvoyées.
                </p>
            </Collapsible>

            {/* ─ Données liées ─ */}
            {hasRelated && (
                <Collapsible
                    title="Données liées"
                    subtitle="Enfants (expand) et jointures avec d'autres ressources"
                    icon={<Link2 className="size-4" />}
                    badge={expand.length}
                >
                    {childResources.length > 0 && (
                        <div className="mb-4">
                            <p className="mb-2 text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Enfants imbriqués (expand)
                            </p>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Ex : fournisseurs <strong>avec leurs sites</strong> ou <strong>leurs contacts</strong> dans le même résultat.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {childResources.map((c) => (
                                    <FieldPill
                                        key={c}
                                        label={c}
                                        checked={expand.includes(c)}
                                        onClick={() => toggle(expand, c, setExpand)}
                                        variant="child"
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {joinableResources.length > 0 && (
                        <div>
                            <p className="mb-2 text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                Jointures avec d'autres ressources
                            </p>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Ex : fournisseurs <strong>qui ont des bons de commande</strong>. Les données de la ressource liée seront incluses.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {joinableResources.map((r) => (
                                    <FieldPill
                                        key={r.key}
                                        label={r.label}
                                        checked={expand.includes(r.key)}
                                        onClick={() => toggle(expand, r.key, setExpand)}
                                        variant="join"
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </Collapsible>
            )}

            {/* ─ Filtres & tri ─ */}
            <Collapsible
                title="Filtres et tri"
                subtitle="Optionnel — réduire et ordonner les résultats"
                icon={<ListFilter className="size-4" />}
                badge={[filterQ, orderBy].filter(Boolean).length}
            >
                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="wiz-filter" className="flex items-center gap-1.5 text-xs font-medium">
                            <Filter className="size-3.5 text-muted-foreground" />
                            Filtre (Oracle REST q=)
                        </Label>
                        <Input
                            id="wiz-filter"
                            value={filterQ}
                            onChange={(e) => setFilterQ(e.target.value)}
                            placeholder={`Ex : Status = "ACTIVE" AND CreationDate > "2024-01-01"`}
                            className="font-mono text-xs"
                        />
                        <p className="text-[11px] text-muted-foreground">
                            Syntaxe Oracle REST : <code>Champ = "valeur"</code>, <code>Champ {'>'} valeur</code>, <code>AND</code>, <code>OR</code>
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="wiz-order" className="flex items-center gap-1.5 text-xs font-medium">
                            <SortAsc className="size-3.5 text-muted-foreground" />
                            Tri (orderBy=)
                        </Label>
                        <Input
                            id="wiz-order"
                            value={orderBy}
                            onChange={(e) => setOrderBy(e.target.value)}
                            placeholder={`Ex : ${allFields[0] ?? 'CreationDate'}:desc`}
                            className="font-mono text-xs"
                        />
                    </div>
                </div>
            </Collapsible>

            {/* ─ Tenant & limite ─ */}
            <div className="flex flex-wrap gap-3 rounded-xl border bg-card p-4">
                <div className="flex max-w-32 flex-col gap-1.5">
                    <Label htmlFor="wiz-limit" className="text-xs font-medium">Limite de lignes</Label>
                    <Input
                        id="wiz-limit"
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

                <div className="flex flex-1 min-w-44 flex-col gap-1.5">
                    <Label htmlFor="wiz-tenant" className="text-xs font-medium">Tenant Oracle</Label>
                    <Select value={tenant} onValueChange={setTenant} disabled={Object.keys(tenants).length === 0}>
                        <SelectTrigger id="wiz-tenant" className="h-9">
                            <SelectValue placeholder="Choisir un tenant" />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(tenants).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div className="flex items-center justify-between border-t pt-4">
                <Button variant="ghost" onClick={onBack}>
                    <ArrowLeft className="mr-1 size-4" />
                    Retour
                </Button>
                <Button onClick={onNext} disabled={!canContinue}>
                    Aperçu & enregistrement
                    <ArrowRight className="ml-1 size-4" />
                </Button>
            </div>
        </div>
    );
}

// ─── ÉTAPE 3 — Aperçu & enregistrement ───────────────────────────────────────

function Step3({
    resource,
    tenant,
    tenants,
    fields,
    expand,
    filterQ,
    orderBy,
    limitStr,
    onBack,
}: {
    resource: Resource;
    tenant: string;
    tenants: Record<string, string>;
    fields: string[];
    expand: string[];
    filterQ: string;
    orderBy: string;
    limitStr: string;
    onBack: () => void;
}) {
    const [name, setName] = useState(resource.label);
    const [visibility, setVisibility] = useState<'private' | 'shared'>('private');
    const [previewStatus, setPreviewStatus] = useState<'idle' | 'loading' | 'done'>('idle');
    const [previewResult, setPreviewResult] = useState<QueryResult | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [activeTab, setActiveTab] = useState<'preview' | 'sql'>('preview');

    const limit = parseLimit(limitStr);
    const tenantLabel = tenants[tenant] ?? tenant;

    const isSaveable =
        previewResult !== null &&
        !previewResult.error &&
        !previewError &&
        previewResult.mode === 'single' &&
        previewResult.resource !== null;

    const resolvedName = name.trim() || resource.label;

    // Description lisible pour la requête sauvegardée
    const description = useMemo(() => {
        const parts: string[] = [resource.label];

        if (expand.length > 0) {
            parts.push(`avec ${expand.join(', ')}`);
        }

        if (filterQ.trim()) {
            parts.push(`filtré : ${filterQ.trim()}`);
        }

        if (orderBy.trim()) {
            parts.push(`trié par ${orderBy.trim()}`);
        }

        return parts.join(' — ');
    }, [resource.label, expand, filterQ, orderBy]);

    const bipSql = useMemo(
        () => generateBipSql(resource, fields, expand, filterQ, orderBy, limit),
        [resource, fields, expand, filterQ, orderBy, limit],
    );

    async function runPreview() {
        setPreviewStatus('loading');
        setPreviewError(null);
        setPreviewResult(null);

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
                body: JSON.stringify({
                    resource_key: resource.key,
                    tenant,
                    fields: fields.length > 0 ? fields : [],
                    expand: expand.length > 0 ? expand : [],
                    filter_q: filterQ.trim() || undefined,
                    order_by: orderBy.trim() || undefined,
                    limit,
                }),
            });

            const data = (await res.json().catch(() => null)) as QueryResult | null;

            if (!res.ok || data === null) {
                setPreviewError(readError(data));
                setPreviewStatus('done');

                return;
            }

            setPreviewResult(data);
        } catch {
            setPreviewError("Erreur réseau lors de la préparation de l'aperçu.");
        } finally {
            setPreviewStatus('done');
        }
    }

    function save() {
        if (!previewResult || saving) {
            return;
        }

        setSaving(true);

        const parameters: Record<string, string | number | boolean | null> =
            previewResult.mode === 'single' && isRecord(previewResult.parameters)
                ? (previewResult.parameters as Record<string, string | number | boolean | null>)
                : {};

        router.post(
            queries.store.url(),
            {
                name: resolvedName,
                description,
                mode: 'single',
                resource_path: previewResult.resource?.path ?? '',
                tenant_key: tenant,
                visibility,
                parameters,
            },
            { onError: () => setSaving(false) },
        );
    }

    return (
        <div className="flex flex-col gap-5">
            {/* En-tête */}
            <div>
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-semibold tracking-tight">Aperçu et enregistrement</h2>
                    <Badge variant="secondary" className="text-xs">
                        {domainIcon(resource.domain)} {resource.label}
                    </Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                    Testez votre requête en direct, puis enregistrez-la.
                </p>
            </div>

            {/* ─ Résumé de la configuration ─ */}
            <div className="rounded-xl border bg-muted/30 p-4 text-xs space-y-2">
                <p className="font-medium text-sm mb-1">Récapitulatif</p>
                <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                    <span className="text-muted-foreground">Ressource</span>
                    <span className="font-medium">{resource.label} <span className="text-muted-foreground">({resource.path})</span></span>

                    <span className="text-muted-foreground">Colonnes</span>
                    <span className="font-mono">
                        {fields.length === 0 ? 'toutes' : fields.join(', ')}
                    </span>

                    {expand.length > 0 && (
                        <>
                            <span className="text-muted-foreground">Données liées</span>
                            <span className="font-mono">{expand.join(', ')}</span>
                        </>
                    )}

                    {filterQ && (
                        <>
                            <span className="text-muted-foreground">Filtre</span>
                            <span className="font-mono italic">{filterQ}</span>
                        </>
                    )}

                    {orderBy && (
                        <>
                            <span className="text-muted-foreground">Tri</span>
                            <span className="font-mono">{orderBy}</span>
                        </>
                    )}

                    <span className="text-muted-foreground">Tenant</span>
                    <span>{tenantLabel}</span>

                    <span className="text-muted-foreground">Limite</span>
                    <span>{limit} lignes</span>
                </div>
            </div>

            {/* ─ Nom + visibilité ─ */}
            <div className="flex flex-wrap gap-3 items-end">
                <div className="flex flex-1 min-w-48 flex-col gap-1.5">
                    <Label htmlFor="wiz-name" className="text-xs font-medium">Nom de la requête</Label>
                    <Input
                        id="wiz-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={resource.label}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label className="text-xs font-medium">Visibilité</Label>
                    <div className="flex gap-2">
                        {(['private', 'shared'] as const).map((v) => (
                            <button
                                key={v}
                                type="button"
                                onClick={() => setVisibility(v)}
                                className={[
                                    'flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm transition-colors',
                                    visibility === v
                                        ? 'border-primary bg-primary/5 text-primary font-medium'
                                        : 'border-border text-muted-foreground hover:border-primary/40',
                                ].join(' ')}
                            >
                                {v === 'private'
                                    ? <><Lock className="size-3.5" /> Privée</>
                                    : <><Globe className="size-3.5" /> Partagée</>}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {/* ─ Bouton aperçu + statut ─ */}
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="secondary"
                    onClick={runPreview}
                    disabled={previewStatus === 'loading'}
                >
                    {previewStatus === 'loading'
                        ? <><Spinner data-icon="inline-start" /> Chargement…</>
                        : <><Eye data-icon="inline-start" /> Tester la requête</>}
                </Button>
                {isSaveable && (
                    <span className="text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                        ✓ {previewResult?.count ?? 0} résultat(s) — prêt à enregistrer
                    </span>
                )}
            </div>

            {/* ─ Squelette chargement ─ */}
            {previewStatus === 'loading' && (
                <div className="space-y-2">
                    <Skeleton className="h-8 w-full" />
                    <Skeleton className="h-8 w-full" />
                    <Skeleton className="h-8 w-3/4" />
                </div>
            )}

            {/* ─ Résultats avec onglets Aperçu / SQL BIP ─ */}
            {previewStatus === 'done' && (
                <div className="rounded-xl border overflow-hidden">
                    {/* Onglets */}
                    <div className="flex border-b bg-muted/30">
                        <button
                            type="button"
                            onClick={() => setActiveTab('preview')}
                            className={[
                                'flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors',
                                activeTab === 'preview'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            <Braces className="size-4" />
                            Aperçu des données
                            {previewResult?.count !== undefined && (
                                <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs font-semibold">
                                    {previewResult.count}
                                </span>
                            )}
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('sql')}
                            className={[
                                'flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors',
                                activeTab === 'sql'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            <Code2 className="size-4" />
                            SQL BIP
                        </button>
                    </div>

                    {/* Contenu onglet Aperçu */}
                    {activeTab === 'preview' && (
                        <div className="p-3">
                            <QueryResultView
                                result={
                                    previewError
                                        ? {
                                              mode: 'single',
                                              tenant,
                                              resource: null,
                                              parameters: null,
                                              columns: null,
                                              analysis: null,
                                              items: [],
                                              count: 0,
                                              hasMore: false,
                                              oracleCalls: [],
                                              clarification: null,
                                              error: previewError,
                                          }
                                        : (previewResult as QueryResult)
                                }
                                tenantLabel={tenantLabel}
                            />
                        </div>
                    )}

                    {/* Contenu onglet SQL BIP */}
                    {activeTab === 'sql' && (
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-3">
                                <div>
                                    <p className="text-sm font-medium">SQL généré pour Oracle BI Publisher</p>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Copiez ce SQL dans votre rapport BIP pour obtenir le même résultat.
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => navigator.clipboard.writeText(bipSql)}
                                >
                                    Copier
                                </Button>
                            </div>
                            <pre className="rounded-lg bg-muted p-4 text-xs font-mono leading-relaxed overflow-x-auto whitespace-pre-wrap text-foreground border">
                                {bipSql}
                            </pre>
                        </div>
                    )}
                </div>
            )}

            {/* ─ Navigation finale ─ */}
            <div className="flex items-center justify-between border-t pt-4">
                <Button variant="ghost" onClick={onBack} disabled={saving}>
                    <ArrowLeft className="mr-1 size-4" />
                    Retour
                </Button>
                <Button onClick={save} disabled={!isSaveable || saving} size="default">
                    {saving
                        ? <><Spinner data-icon="inline-start" /> Enregistrement…</>
                        : <><Save data-icon="inline-start" /> Enregistrer la requête</>}
                </Button>
            </div>
        </div>
    );
}

// ─── QueryWizard (composant racine) ───────────────────────────────────────────

export function QueryWizard({ resourceSuggestions, tenants, defaultTenant }: WizardProps) {
    const [step, setStep] = useState<Step>(1);
    const [resource, setResource] = useState<Resource | null>(null);

    // État étape 2
    const [fields, setFields] = useState<string[]>([]);
    const [expand, setExpand] = useState<string[]>([]);
    const [filterQ, setFilterQ] = useState('');
    const [orderBy, setOrderBy] = useState('');
    const [tenant, setTenant] = useState(defaultTenant || Object.keys(tenants)[0] || '');
    const [limit, setLimit] = useState(String(DEFAULT_LIMIT));

    const topRef = useRef<HTMLDivElement>(null);

    function goTo(s: Step) {
        setStep(s);
        setTimeout(() => topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    }

    // Reset état étape 2 quand on change de ressource
    function selectResource(r: Resource) {
        if (resource?.key !== r.key) {
            setFields([]);
            setExpand([]);
            setFilterQ('');
            setOrderBy('');
        }

        setResource(r);
    }

    return (
        <div ref={topRef} className="max-w-2xl pb-12">
            <StepBar step={step} />

            {step === 1 && (
                <Step1
                    resources={resourceSuggestions}
                    selected={resource}
                    onSelect={selectResource}
                    onNext={() => goTo(2)}
                />
            )}

            {step === 2 && resource && (
                <Step2
                    resource={resource}
                    allResources={resourceSuggestions}
                    tenants={tenants}
                    fields={fields}
                    setFields={setFields}
                    expand={expand}
                    setExpand={setExpand}
                    filterQ={filterQ}
                    setFilterQ={setFilterQ}
                    orderBy={orderBy}
                    setOrderBy={setOrderBy}
                    tenant={tenant}
                    setTenant={setTenant}
                    limit={limit}
                    setLimit={setLimit}
                    onBack={() => goTo(1)}
                    onNext={() => goTo(3)}
                />
            )}

            {step === 3 && resource && (
                <Step3
                    resource={resource}
                    tenant={tenant}
                    tenants={tenants}
                    fields={fields}
                    expand={expand}
                    filterQ={filterQ}
                    orderBy={orderBy}
                    limitStr={limit}
                    onBack={() => goTo(2)}
                />
            )}
        </div>
    );
}
