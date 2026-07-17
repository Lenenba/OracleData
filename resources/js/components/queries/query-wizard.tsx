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
    Lock,
    Plus,
    Save,
    Search,
    SortAsc,
    Table2,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { JoinKeyDef, ResourceSuggestion } from '@/components/queries/query-form';
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

// Étend ResourceSuggestion avec les champs enrichis du catalogue
type Resource = ResourceSuggestion & {
    child_fields?: Record<string, string[]>;
    join_keys?: Record<string, JoinKeyDef>;
};

// Une ligne du constructeur de filtres
type FilterRow = {
    id: string;
    field: string;
    operator: string;
    value: string;
    conjunction: 'AND' | 'OR';
};

// Champs sélectionnés pour chaque enfant (key = nom de l'enfant)
type ChildFieldsMap = Record<string, string[]>;

// Champs connus d'un enfant (issus du catalogue si disponible, sinon vide)
type ChildFieldsCatalog = Record<string, string[]>;

type WizardMode = 'create' | 'edit';

type WizardInitialState = {
    queryId?: number;
    name?: string;
    visibility?: 'private' | 'shared';
    resourceKey?: string;
    tenantKey?: string;
    fields?: string[];
    expand?: string[];
    childFields?: ChildFieldsMap;
    filterQ?: string;
    orderBy?: string;
    limit?: number;
};

type WizardProps = {
    resourceSuggestions: Resource[];
    tenants: Record<string, string>;
    defaultTenant: string;
    mode?: WizardMode;
    initialState?: WizardInitialState;
};

// ─── Constantes ───────────────────────────────────────────────────────────────

const DEFAULT_LIMIT = 25;

const FILTER_OPERATORS = [
    { value: '=', label: '= égal à' },
    { value: '!=', label: '≠ différent de' },
    { value: '>', label: '> supérieur à' },
    { value: '>=', label: '>= supérieur ou égal' },
    { value: '<', label: '< inférieur à' },
    { value: '<=', label: '<= inférieur ou égal' },
    { value: 'LIKE', label: '~ contient (LIKE)' },
    { value: 'STARTSWITH', label: 'commence par' },
];

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
    Projets: {
        color: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800',
        icon: '📐',
    },
    Actifs: {
        color: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800',
        icon: '🏗️',
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

function newFilterRow(): FilterRow {
    return { id: Math.random().toString(36).slice(2), field: '', operator: '=', value: '', conjunction: 'AND' };
}

/**
 * Convertit les lignes du filter-builder en syntaxe Oracle REST q=
 * Ex: Supplier = "Acme" AND Status = "ACTIVE"
 * Pour LIKE: Supplier LIKE "Acme*"
 * Pour STARTSWITH: on génère LIKE "val*"
 */
function filterRowsToQ(rows: FilterRow[], parentFields: string[]): string {
    const valid = rows.filter(
        (r) => r.field !== '' && r.value.trim() !== '' && parentFields.includes(r.field),
    );

    if (valid.length === 0) {
return '';
}

    return valid
        .map((r, i) => {
            const prefix = i > 0 ? `${r.conjunction} ` : '';
            const val = r.value.trim();
            let expr: string;

            if (r.operator === 'STARTSWITH') {
                expr = `${r.field} LIKE "${val}*"`;
            } else if (r.operator === 'LIKE') {
                expr = `${r.field} LIKE "*${val}*"`;
            } else if (/^\d/.test(val) || val === 'true' || val === 'false') {
                // numeric/boolean — no quotes
                expr = `${r.field} ${r.operator} ${val}`;
            } else {
                expr = `${r.field} ${r.operator} "${val}"`;
            }

            return `${prefix}${expr}`;
        })
        .join(' ');
}

/**
 * Parse une chaîne q= Oracle REST en lignes de filter-builder.
 * Heuristique : supporte AND/OR de conditions simples.
 */
function qToFilterRows(q: string, parentFields: string[]): FilterRow[] {
    if (!q.trim()) {
return [];
}

    // Tokenize: split on AND/OR boundaries
    const tokenRe =
        /(?:(AND|OR)\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|LIKE|=|>|<)\s*"([^"]*)"|(?:(AND|OR)\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|=|>|<)\s*(\S+)/gi;

    const rows: FilterRow[] = [];
    let match: RegExpExecArray | null;

    while ((match = tokenRe.exec(q)) !== null) {
        const conj = (match[1] ?? match[5] ?? 'AND').toUpperCase() as 'AND' | 'OR';
        const field = match[2] ?? match[6] ?? '';
        const op = (match[3] ?? match[7] ?? '=').toUpperCase();
        const val = match[4] ?? match[8] ?? '';

        if (!parentFields.includes(field)) {
continue;
}

        rows.push({
            id: Math.random().toString(36).slice(2),
            field,
            operator: op,
            value: val.replace(/\*$/, '').replace(/^\*/, '').replace(/\*/g, ''),
            conjunction: rows.length === 0 ? 'AND' : conj,
        });
    }

    return rows.length > 0 ? rows : [];
}

// Génère un SQL BIP (Oracle BI Publisher) à partir des paramètres du wizard
function generateBipSql(
    resource: Resource,
    fields: string[],
    expand: string[],
    childFields: ChildFieldsMap,
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
    sql += `SELECT\n${selectCols}`;

    if (expand.length > 0) {
        expand.forEach((child) => {
            const childTable = child.toUpperCase();
            const cFields = childFields[child] ?? [];

            if (cFields.length > 0) {
                cFields.forEach((cf) => {
                    sql += `,\n  ${childTable}.${cf}`;
                });
            } else {
                sql += `,\n  ${childTable}.*`;
            }
        });
    }

    sql += `\nFROM ${tableName}`;

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
                            onValueChange={(v) => update(row.id, { conjunction: v as 'AND' | 'OR' })}
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
                        <SelectTrigger className="h-8 min-w-36 flex-1 text-xs font-mono">
                            <SelectValue placeholder="Champ…" />
                        </SelectTrigger>
                        <SelectContent>
                            {parentFields.map((f) => (
                                <SelectItem key={f} value={f} className="font-mono text-xs">
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
                                <SelectItem key={op.value} value={op.value} className="text-xs">
                                    {op.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {/* Valeur */}
                    <Input
                        className="h-8 min-w-32 flex-1 text-xs font-mono"
                        placeholder={
                            row.operator === 'LIKE' || row.operator === 'STARTSWITH'
                                ? 'ex : Acme'
                                : 'valeur…'
                        }
                        value={row.value}
                        onChange={(e) => update(row.id, { value: e.target.value })}
                    />

                    {/* Supprimer */}
                    <button
                        type="button"
                        onClick={() => remove(row.id)}
                        className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors"
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
                    <p className="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                        Syntaxe Oracle REST générée
                    </p>
                    <code className="text-xs font-mono text-foreground">
                        {filterRowsToQ(rows, parentFields) || '…'}
                    </code>
                </div>
            )}
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
                    Choisissez le jeu de données principal à interroger. Vous pourrez enrichir
                    avec des données liées et des filtres avancés à l'étape suivante.
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
                            <div className="flex items-start justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span className="text-lg leading-none">{domainIcon(r.domain)}</span>
                                    <span className="font-semibold text-sm leading-snug">{r.label}</span>
                                </div>
                                <span
                                    className={`shrink-0 rounded-full border px-2 py-0.5 text-xs font-medium ${domainColor(r.domain)}`}
                                >
                                    {r.domain}
                                </span>
                            </div>

                            <p className="text-xs text-muted-foreground leading-relaxed line-clamp-2">
                                {r.description}
                            </p>

                            {(r.fields ?? []).length > 0 && (
                                <div className="flex flex-wrap gap-1">
                                    {(r.fields ?? []).slice(0, 4).map((f) => (
                                        <span
                                            key={f}
                                            className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                        >
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

                            {((r.child_resources ?? []).length > 0 ||
                                Object.keys(r.join_keys ?? {}).length > 0) && (
                                <div className="flex flex-wrap gap-1 border-t pt-2">
                                    {(r.child_resources ?? []).slice(0, 2).map((c) => (
                                        <span
                                            key={c}
                                            className="rounded border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300"
                                        >
                                            ↳ {c}
                                        </span>
                                    ))}
                                    {Object.keys(r.join_keys ?? {}).slice(0, 2).map((target) => (
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
    onBack: () => void;
    onNext: () => void;
}) {
    const allFields = resource.fields ?? [];
    const childResources = useMemo(() => resource.child_resources ?? [], [resource.child_resources]);
    const joinKeysDefs = resource.join_keys ?? {};
    const joinTargets = Object.keys(joinKeysDefs);

    // Ressources joignables — on affiche leur label enrichi
    const joinableResources = allResources.filter((r) => joinTargets.includes(r.key));

    // Catalogue des champs enfants :
    // • enfants imbriqués (expand) → child_fields du catalogue
    // • ressources joignables       → leurs champs top-level
    const childFieldsCatalog = useMemo<ChildFieldsCatalog>(() => {
        const map: ChildFieldsCatalog = {};

        // 1. Champs des enfants imbriqués issus du catalogue PHP (child_fields)
        const catalogChildFields = resource.child_fields ?? {};

        for (const c of childResources) {
            map[c] = catalogChildFields[c] ?? [];
        }

        // 2. Champs des ressources joignables (leurs propres fields top-level)
        for (const jr of joinableResources) {
            map[jr.key] = jr.fields ?? [];
        }

        return map;
    }, [resource.child_fields, childResources, joinableResources]);

    function toggle(list: string[], item: string, set: (v: string[]) => void) {
        set(list.includes(item) ? list.filter((x) => x !== item) : [...list, item]);
    }

    function toggleChildField(childKey: string, field: string) {
        const current = childFields[childKey] ?? [];
        const updated = current.includes(field)
            ? current.filter((f) => f !== field)
            : [...current, field];

        setChildFields({ ...childFields, [childKey]: updated });
    }

    function toggleExpand(key: string) {
        if (expand.includes(key)) {
            // Déselectionner : retirer aussi les childFields
            setExpand(expand.filter((x) => x !== key));
            const updated = { ...childFields };
            delete updated[key];
            setChildFields(updated);
        } else {
            setExpand([...expand, key]);
        }
    }

    const canContinue = tenant !== '';
    const hasRelated = childResources.length > 0 || joinTargets.length > 0;
    const activeFilterCount = filterRows.filter((r) => r.field && r.value.trim()).length;

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
                    Choisissez les colonnes, enrichissez avec des données liées, et filtrez
                    précisément (par nom, numéro, statut, date…).
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
                    subtitle="Enfants (expand) et jointures — choisissez aussi leurs colonnes"
                    icon={<Link2 className="size-4" />}
                    badge={expand.length}
                >
                    {childResources.length > 0 && (
                        <div className="mb-5">
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Enfants imbriqués (expand)
                            </p>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Ex : fournisseurs <strong>avec leurs sites</strong> ou{' '}
                                <strong>leurs contacts</strong> dans le même résultat.
                            </p>
                            <div className="flex flex-wrap gap-2 mb-3">
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
                                .map((c) => {
                                    const knownFields = childFieldsCatalog[c] ?? [];

                                    return (
                                        <div
                                            key={c}
                                            className="mb-3 rounded-lg border border-blue-200 bg-blue-50/40 p-3 dark:border-blue-800 dark:bg-blue-950/20"
                                        >
                                            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-blue-700 dark:text-blue-300">
                                                <ChevronRight className="size-3.5" />
                                                Colonnes de «{c}» à inclure
                                            </p>
                                            {knownFields.length > 0 ? (
                                                <>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {knownFields.map((f) => (
                                                            <FieldPill
                                                                key={f}
                                                                label={f}
                                                                checked={(childFields[c] ?? []).includes(f)}
                                                                onClick={() => toggleChildField(c, f)}
                                                                variant="child"
                                                            />
                                                        ))}
                                                    </div>
                                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                                        Sans sélection → tous les champs de l'enfant sont inclus.
                                                    </p>
                                                </>
                                            ) : (
                                                <p className="text-xs text-muted-foreground italic">
                                                    Les champs disponibles pour «{c}» sont déterminés à l'exécution.
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                        </div>
                    )}

                    {joinableResources.length > 0 && (
                        <div>
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Jointures avec d'autres ressources
                            </p>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Enrichissez les résultats avec des données d'une ressource liée.
                            </p>
                            <div className="flex flex-wrap gap-2 mb-3">
                                {joinableResources.map((r) => {
                                    const joinDef = joinKeysDefs[r.key];

                                    return (
                                        <FieldPill
                                            key={r.key}
                                            label={joinDef?.label ?? r.label}
                                            checked={expand.includes(r.key)}
                                            onClick={() => toggleExpand(r.key)}
                                            variant="join"
                                        />
                                    );
                                })}
                            </div>

                            {/* Sélection des champs de chaque jointure activée */}
                            {joinableResources
                                .filter((r) => expand.includes(r.key))
                                .map((r) => {
                                    const joinDef = joinKeysDefs[r.key];
                                    const knownFields = childFieldsCatalog[r.key] ?? [];

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
                                            {knownFields.length > 0 ? (
                                                <>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {knownFields.map((f) => (
                                                            <FieldPill
                                                                key={f}
                                                                label={f}
                                                                checked={(childFields[r.key] ?? []).includes(f)}
                                                                onClick={() =>
                                                                    toggleChildField(r.key, f)
                                                                }
                                                                variant="join"
                                                            />
                                                        ))}
                                                    </div>
                                                    <p className="mt-1.5 text-[11px] text-muted-foreground">
                                                        Sans sélection → tous les champs de la ressource liée sont inclus.
                                                    </p>
                                                </>
                                            ) : (
                                                <p className="text-xs text-muted-foreground italic">
                                                    Champs déterminés à l'exécution.
                                                </p>
                                            )}
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
                                        ? 'border-primary bg-primary/10 text-primary font-semibold'
                                        : 'border-border text-muted-foreground hover:border-primary/50',
                                ].join(' ')}
                            >
                                {f}
                                {orderBy === `${f}:asc` && ' ↑'}
                                {orderBy === `${f}:desc` && ' ↓'}
                            </button>
                        ))}
                    </div>
                    <Label htmlFor="wiz-order" className="mt-2 flex items-center gap-1.5 text-xs font-medium">
                        <SortAsc className="size-3.5 text-muted-foreground" />
                        Valeur libre (orderBy=)
                    </Label>
                    <Input
                        id="wiz-order"
                        value={orderBy}
                        onChange={(e) => setOrderBy(e.target.value)}
                        placeholder={`Ex : ${allFields[0] ?? 'CreationDate'}:desc`}
                        className="font-mono text-xs"
                    />
                </div>
            </Collapsible>

            {/* ─ Tenant & limite ─ */}
            <div className="flex flex-wrap gap-3 rounded-xl border bg-card p-4">
                <div className="flex max-w-32 flex-col gap-1.5">
                    <Label htmlFor="wiz-limit" className="text-xs font-medium">
                        Limite de lignes
                    </Label>
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
                    <Label htmlFor="wiz-tenant" className="text-xs font-medium">
                        Tenant Oracle
                    </Label>
                    <Select
                        value={tenant}
                        onValueChange={setTenant}
                        disabled={Object.keys(tenants).length === 0}
                    >
                        <SelectTrigger id="wiz-tenant" className="h-9">
                            <SelectValue placeholder="Choisir un tenant" />
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
    childFields,
    filterRows,
    orderBy,
    limitStr,
    wizardMode,
    queryId,
    initialName,
    initialVisibility,
    onBack,
}: {
    resource: Resource;
    tenant: string;
    tenants: Record<string, string>;
    fields: string[];
    expand: string[];
    childFields: ChildFieldsMap;
    filterRows: FilterRow[];
    orderBy: string;
    limitStr: string;
    wizardMode: WizardMode;
    queryId?: number;
    initialName?: string;
    initialVisibility?: 'private' | 'shared';
    onBack: () => void;
}) {
    const [name, setName] = useState(initialName ?? resource.label);
    const [visibility, setVisibility] = useState<'private' | 'shared'>(initialVisibility ?? 'private');
    const [previewStatus, setPreviewStatus] = useState<'idle' | 'loading' | 'done'>('idle');
    const [previewResult, setPreviewResult] = useState<QueryResult | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [activeTab, setActiveTab] = useState<'preview' | 'sql'>('preview');

    const limit = parseLimit(limitStr);
    const tenantLabel = tenants[tenant] ?? tenant;

    // Derive the filter_q from filterRows
    const filterQ = filterRowsToQ(filterRows, resource.fields ?? []);

    const isSaveable =
        previewResult !== null &&
        !previewResult.error &&
        !previewError &&
        previewResult.mode === 'single' &&
        previewResult.resource !== null;

    const resolvedName = name.trim() || resource.label;

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
        () => generateBipSql(resource, fields, expand, childFields, filterQ, orderBy, limit),
        [resource, fields, expand, childFields, filterQ, orderBy, limit],
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

        const baseParams: Record<string, string | number | boolean | null> =
            previewResult.mode === 'single' && isRecord(previewResult.parameters)
                ? (previewResult.parameters as Record<string, string | number | boolean | null>)
                : {};

        // Store resource_key so the wizard can be pre-filled on next edit
        const parameters = { ...baseParams, resource_key: resource.key };

        const payload = {
            name: resolvedName,
            description,
            mode: 'single',
            resource_path: previewResult.resource?.path ?? '',
            tenant_key: tenant,
            visibility,
            parameters,
        };

        if (wizardMode === 'edit' && queryId !== undefined) {
            router.put(
                queries.update.url(queryId),
                payload,
                { onError: () => setSaving(false) },
            );
        } else {
            router.post(
                queries.store.url(),
                payload,
                { onError: () => setSaving(false) },
            );
        }
    }

    return (
        <div className="flex flex-col gap-5">
            {/* En-tête */}
            <div>
                <div className="flex items-center gap-2">
                    <h2 className="text-lg font-semibold tracking-tight">
                        {wizardMode === 'edit' ? 'Modifier et ré-exécuter' : 'Aperçu et enregistrement'}
                    </h2>
                    <Badge variant="secondary" className="text-xs">
                        {domainIcon(resource.domain)} {resource.label}
                    </Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                    {wizardMode === 'edit'
                        ? 'Testez la requête modifiée, puis enregistrez les changements.'
                        : 'Testez votre requête en direct, puis enregistrez-la.'}
                </p>
            </div>

            {/* ─ Nom + visibilité ─ */}
            <div className="flex flex-wrap gap-3 items-end">
                <div className="flex flex-1 min-w-48 flex-col gap-1.5">
                    <Label htmlFor="wiz-name" className="text-xs font-medium">
                        Nom de la requête
                    </Label>
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
                                {v === 'private' ? (
                                    <>
                                        <Lock className="size-3.5" /> Privée
                                    </>
                                ) : (
                                    <>
                                        <Globe className="size-3.5" /> Partagée
                                    </>
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {/* ─ Résumé de la configuration ─ */}
            <div className="rounded-xl border bg-muted/30 p-4 text-xs space-y-2">
                <p className="font-medium text-sm mb-1">Récapitulatif</p>
                <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                    <span className="text-muted-foreground">Ressource</span>
                    <span className="font-medium">
                        {resource.label}{' '}
                        <span className="text-muted-foreground">({resource.path})</span>
                    </span>

                    <span className="text-muted-foreground">Colonnes</span>
                    <span className="font-mono">
                        {fields.length === 0 ? 'toutes' : fields.join(', ')}
                    </span>

                    {expand.length > 0 && (
                        <>
                            <span className="text-muted-foreground">Données liées</span>
                            <div className="flex flex-wrap gap-1">
                                {expand.map((e) => (
                                    <span key={e} className="font-mono">
                                        {e}
                                        {(childFields[e] ?? []).length > 0 && (
                                            <span className="text-muted-foreground">
                                                {' '}({(childFields[e] ?? []).join(', ')})
                                            </span>
                                        )}
                                    </span>
                                ))}
                            </div>
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

            {/* ─ Bouton aperçu + statut ─ */}
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="secondary"
                    onClick={runPreview}
                    disabled={previewStatus === 'loading'}
                >
                    {previewStatus === 'loading' ? (
                        <>
                            <Spinner data-icon="inline-start" /> Chargement…
                        </>
                    ) : (
                        <>
                            <Eye data-icon="inline-start" /> Tester la requête
                        </>
                    )}
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

                    {activeTab === 'sql' && (
                        <div className="p-4">
                            <div className="flex items-center justify-between mb-3">
                                <div>
                                    <p className="text-sm font-medium">
                                        SQL généré pour Oracle BI Publisher
                                    </p>
                                    <p className="text-xs text-muted-foreground mt-0.5">
                                        Copiez ce SQL dans votre rapport BIP.
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => void navigator.clipboard.writeText(bipSql)}
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
                    {saving ? (
                        <>
                            <Spinner data-icon="inline-start" /> Enregistrement…
                        </>
                    ) : (
                        <>
                            <Save data-icon="inline-start" />
                            {wizardMode === 'edit' ? 'Mettre à jour' : 'Enregistrer la requête'}
                        </>
                    )}
                </Button>
            </div>
        </div>
    );
}

// ─── QueryWizard (composant racine) ───────────────────────────────────────────

export function QueryWizard({
    resourceSuggestions,
    tenants,
    defaultTenant,
    mode = 'create',
    initialState,
}: WizardProps) {
    // Resolve initial resource from initialState.resourceKey
    const resourceKey = initialState?.resourceKey;
    const initialResource = useMemo(
        () =>
            resourceKey
                ? (resourceSuggestions.find((r) => r.key === resourceKey) ?? null)
                : null,
        [resourceSuggestions, resourceKey],
    );

    const [step, setStep] = useState<Step>(initialResource ? 2 : 1);
    const [resource, setResource] = useState<Resource | null>(initialResource);

    // État étape 2
    const [fields, setFields] = useState<string[]>(initialState?.fields ?? []);
    const [expand, setExpand] = useState<string[]>(initialState?.expand ?? []);
    const [childFields, setChildFields] = useState<ChildFieldsMap>(initialState?.childFields ?? {});
    const [filterRows, setFilterRows] = useState<FilterRow[]>(() => {
        const q = initialState?.filterQ ?? '';
        const pFields = initialResource?.fields ?? [];

        if (!q) {
return [];
}

        const parsed = qToFilterRows(q, pFields);

        return parsed.length > 0 ? parsed : [];
    });
    const [orderBy, setOrderBy] = useState(initialState?.orderBy ?? '');
    const [tenant, setTenant] = useState(
        initialState?.tenantKey ?? defaultTenant ?? Object.keys(tenants)[0] ?? '',
    );
    const [limit, setLimit] = useState(String(initialState?.limit ?? DEFAULT_LIMIT));

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
            setChildFields({});
            setFilterRows([]);
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
                    childFields={childFields}
                    setChildFields={setChildFields}
                    filterRows={filterRows}
                    setFilterRows={setFilterRows}
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
                    childFields={childFields}
                    filterRows={filterRows}
                    orderBy={orderBy}
                    limitStr={limit}
                    wizardMode={mode}
                    queryId={initialState?.queryId}
                    initialName={initialState?.name}
                    initialVisibility={initialState?.visibility}
                    onBack={() => goTo(2)}
                />
            )}
        </div>
    );
}
