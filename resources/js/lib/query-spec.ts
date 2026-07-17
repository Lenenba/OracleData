/**
 * Types et helpers partagés du query builder Oracle : spécification de la
 * requête (champs, enfants expand, jointures, filtres), conversion vers la
 * syntaxe REST Oracle (q=) et génération du SQL BI Publisher.
 */

// ─── Types ────────────────────────────────────────────────────────────────────

export type JoinKeyDef = {
    local_key: string;
    remote_key: string;
    label: string;
};

export type ResourceSuggestion = {
    key: string;
    label: string;
    description: string;
    domain: string;
    method: string;
    path: string;
    keywords: string[];
    preview_fields: string[];
    fields?: string[];
    child_resources?: string[];
    child_fields?: Record<string, string[]>;
    join_keys?: Record<string, JoinKeyDef>;
};

// Une ligne du constructeur de filtres
export type FilterRow = {
    id: string;
    field: string;
    operator: string;
    value: string;
    conjunction: 'AND' | 'OR';
};

// Champs sélectionnés pour chaque enfant/jointure (key = nom de l'enfant)
export type ChildFieldsMap = Record<string, string[]>;

// ─── Constantes ───────────────────────────────────────────────────────────────

export const DEFAULT_LIMIT = 25;

export const FILTER_OPERATORS = [
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

export function domainColor(domain: string) {
    return (
        DOMAIN_META[domain]?.color ??
        'bg-muted text-muted-foreground border-border'
    );
}

export function domainIcon(domain: string) {
    return DOMAIN_META[domain]?.icon ?? '📋';
}

export function parseLimit(v: string): number {
    const n = Number.parseInt(v, 10);

    return Number.isNaN(n) ? DEFAULT_LIMIT : Math.min(500, Math.max(1, n));
}

export function isRecord(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null;
}

export function readError(data: unknown): string {
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

export function newFilterRow(): FilterRow {
    return {
        id: Math.random().toString(36).slice(2),
        field: '',
        operator: '=',
        value: '',
        conjunction: 'AND',
    };
}

/**
 * Parse une chaîne CSV persistée (parameters.fields / expand / joins) en liste.
 */
export function parseCsv(value: unknown): string[] {
    if (typeof value === 'string' && value.trim()) {
        return value
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);
    }

    if (Array.isArray(value)) {
        return (value as unknown[]).map(String).filter(Boolean);
    }

    return [];
}

/**
 * Parse la map parameters.child_fields ({ enfant: string[] }) persistée.
 */
export function parseChildFields(value: unknown): ChildFieldsMap {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return {};
    }

    const map: ChildFieldsMap = {};

    for (const [key, fields] of Object.entries(
        value as Record<string, unknown>,
    )) {
        const parsed = parseCsv(fields);

        if (parsed.length > 0) {
            map[key] = parsed;
        }
    }

    return map;
}

/**
 * Convertit les lignes du filter-builder en syntaxe Oracle REST q=
 * Ex: Supplier = "Acme" AND Status = "ACTIVE"
 * Pour LIKE: Supplier LIKE "Acme*"
 * Pour STARTSWITH: on génère LIKE "val*"
 */
export function filterRowsToQ(
    rows: FilterRow[],
    parentFields: string[],
): string {
    const valid = rows.filter(
        (r) =>
            r.field !== '' &&
            r.value.trim() !== '' &&
            parentFields.includes(r.field),
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
                // numérique/booléen — sans guillemets
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
export function qToFilterRows(q: string, parentFields: string[]): FilterRow[] {
    if (!q.trim()) {
        return [];
    }

    // Tokenize: split on AND/OR boundaries
    const tokenRe =
        /(?:(AND|OR)\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|LIKE|=|>|<)\s*"([^"]*)"|(?:(AND|OR)\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*(>=|<=|!=|=|>|<)\s*(\S+)/gi;

    const rows: FilterRow[] = [];
    let match: RegExpExecArray | null;

    while ((match = tokenRe.exec(q)) !== null) {
        const conj = (match[1] ?? match[5] ?? 'AND').toUpperCase() as
            | 'AND'
            | 'OR';
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

    return rows;
}

// Génère un SQL BIP (Oracle BI Publisher) à partir des paramètres du builder
export function generateBipSql(
    resource: ResourceSuggestion,
    fields: string[],
    expand: string[],
    joins: string[],
    childFields: ChildFieldsMap,
    filterQ: string,
    orderBy: string,
    limit: number,
): string {
    const cols = fields.length > 0 ? fields : (resource.fields ?? []);
    const tableName = resource.key.toUpperCase();
    const selectCols = cols.map((f) => `  ${tableName}.${f}`).join(',\n');
    const joinKeysDefs = resource.join_keys ?? {};

    let sql = `-- Requête générée par OracleData Query Builder\n`;
    sql += `-- Ressource : ${resource.label} (${resource.domain})\n`;
    sql += `-- Chemin REST : ${resource.path}\n\n`;
    sql += `SELECT\n${selectCols}`;

    [...expand, ...joins].forEach((related) => {
        const relatedTable = related.toUpperCase();
        const cFields = childFields[related] ?? [];

        if (cFields.length > 0) {
            cFields.forEach((cf) => {
                sql += `,\n  ${relatedTable}.${cf}`;
            });
        } else {
            sql += `,\n  ${relatedTable}.*`;
        }
    });

    sql += `\nFROM ${tableName}`;

    expand.forEach((child) => {
        const childTable = child.toUpperCase();
        sql += `\nLEFT JOIN ${tableName}_${childTable} ${childTable}`;
        sql += `\n  ON ${childTable}.PARENT_${tableName}_ID = ${tableName}.${tableName.replace(/S$/, '')}ID`;
    });

    joins.forEach((target) => {
        const joinDef = joinKeysDefs[target];
        const targetTable = target.toUpperCase();

        if (joinDef) {
            sql += `\nLEFT JOIN ${targetTable}`;
            sql += `\n  ON ${targetTable}.${joinDef.remote_key} = ${tableName}.${joinDef.local_key}`;
        }
    });

    if (filterQ.trim()) {
        sql += `\nWHERE ${filterQ.trim()}`;
    }

    if (orderBy.trim()) {
        sql += `\nORDER BY ${orderBy.trim()}`;
    }

    sql += `\nFETCH FIRST ${limit} ROWS ONLY`;

    return sql;
}
