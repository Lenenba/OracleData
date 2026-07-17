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

export type SqlRelationDef = {
    table: string;
    alias?: string;
    join?: string;
    columns?: Record<string, string>;
};

export type SqlSourceDef = SqlRelationDef & {
    child_tables?: Record<string, SqlRelationDef>;
    joins?: Record<string, SqlRelationDef>;
    note?: string;
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
    sql?: SqlSourceDef | null;
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

function toOracleColumn(field: string): string {
    return field
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .replace(/[^A-Za-z0-9_]/g, '_')
        .toUpperCase();
}

function relationAlias(relation: SqlRelationDef | undefined, fallback: string) {
    return relation?.alias ?? fallback;
}

function qualifySqlExpression(expression: string, alias: string): string {
    const trimmed = expression.trim();

    if (/[\s().']/.test(trimmed) || trimmed.includes('.')) {
        return trimmed;
    }

    return `${alias}.${trimmed}`;
}

function sqlColumnExpression(
    relation: SqlRelationDef | undefined,
    alias: string,
    field: string,
): string {
    return qualifySqlExpression(
        relation?.columns?.[field] ?? toOracleColumn(field),
        alias,
    );
}

function selectSqlColumn(
    relation: SqlRelationDef | undefined,
    alias: string,
    field: string,
    outputName = field,
): string {
    return `  ${sqlColumnExpression(relation, alias, field)} AS "${outputName}"`;
}

function sqlStringLiterals(expression: string): string {
    return expression.replace(/"([^"]*)"/g, (_match, value: string) => {
        return `'${value.replace(/'/g, "''")}'`;
    });
}

function mapSqlFilterExpression(
    expression: string,
    relation: SqlRelationDef | undefined,
    alias: string,
    fields: string[],
): string {
    const knownFields = [
        ...new Set([...fields, ...Object.keys(relation?.columns ?? {})]),
    ].sort((a, b) => b.length - a.length);

    let mapped = expression;

    for (const field of knownFields) {
        mapped = mapped.replace(
            new RegExp(`\\b${field}\\b`, 'g'),
            sqlColumnExpression(relation, alias, field),
        );
    }

    return sqlStringLiterals(mapped);
}

function mapSqlOrderBy(
    orderBy: string,
    relation: SqlRelationDef | undefined,
    alias: string,
    fields: string[],
): string {
    return orderBy
        .split(',')
        .map((clause) => {
            const [field, direction] = clause.split(':').map((v) => v.trim());

            if (!field) {
                return '';
            }

            const mappedField = fields.includes(field)
                ? sqlColumnExpression(relation, alias, field)
                : field;
            const mappedDirection =
                direction?.toLowerCase() === 'desc' ? 'DESC' : 'ASC';

            return `${mappedField} ${mappedDirection}`;
        })
        .filter(Boolean)
        .join(', ');
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
    const sqlSource = resource.sql ?? undefined;
    const tableName = sqlSource?.table ?? resource.key.toUpperCase();
    const tableAlias = relationAlias(sqlSource, tableName);
    const selectCols = cols
        .map((f) => selectSqlColumn(sqlSource, tableAlias, f))
        .join(',\n');
    const joinKeysDefs = resource.join_keys ?? {};

    let sql = `-- Requête générée par OracleData Query Builder\n`;
    sql += `-- Ressource : ${resource.label} (${resource.domain})\n`;
    sql += `-- Chemin REST : ${resource.path}\n\n`;
    if (sqlSource?.note) {
        sql += `-- Source SQL indicative : ${sqlSource.note}\n\n`;
    }
    sql += `SELECT\n${selectCols}`;

    [...expand, ...joins].forEach((related) => {
        const relation =
            sqlSource?.child_tables?.[related] ?? sqlSource?.joins?.[related];
        const relatedTable = relation?.table ?? related.toUpperCase();
        const relatedAlias = relationAlias(relation, relatedTable);
        const cFields = childFields[related] ?? [];

        if (cFields.length > 0) {
            cFields.forEach((cf) => {
                sql += `,\n${selectSqlColumn(
                    relation,
                    relatedAlias,
                    cf,
                    `${related}.${cf}`,
                )}`;
            });
        } else {
            sql += `,\n  ${relatedAlias}.*`;
        }
    });

    sql += `\nFROM ${tableName} ${tableAlias}`;

    expand.forEach((child) => {
        const relation = sqlSource?.child_tables?.[child];
        const childTable =
            relation?.table ?? `${tableName}_${child.toUpperCase()}`;
        const childAlias = relationAlias(relation, child.toUpperCase());

        sql += `\nLEFT JOIN ${childTable} ${childAlias}`;

        if (relation?.join) {
            sql += `\n  ON ${relation.join}`;
        } else {
            sql += `\n  ON ${childAlias}.PARENT_${tableName}_ID = ${tableAlias}.${tableName.replace(/S$/, '')}ID`;
        }
    });

    joins.forEach((target) => {
        const joinDef = joinKeysDefs[target];
        const relation = sqlSource?.joins?.[target];
        const targetTable = relation?.table ?? target.toUpperCase();
        const targetAlias = relationAlias(relation, targetTable);

        if (relation?.join) {
            sql += `\nLEFT JOIN ${targetTable} ${targetAlias}`;
            sql += `\n  ON ${relation.join}`;
        } else if (joinDef) {
            sql += `\nLEFT JOIN ${targetTable} ${targetAlias}`;
            sql += `\n  ON ${sqlColumnExpression(relation, targetAlias, joinDef.remote_key)} = ${sqlColumnExpression(sqlSource, tableAlias, joinDef.local_key)}`;
        }
    });

    if (filterQ.trim()) {
        sql += `\nWHERE ${mapSqlFilterExpression(
            filterQ.trim(),
            sqlSource,
            tableAlias,
            resource.fields ?? [],
        )}`;
    }

    if (orderBy.trim()) {
        sql += `\nORDER BY ${mapSqlOrderBy(
            orderBy.trim(),
            sqlSource,
            tableAlias,
            resource.fields ?? [],
        )}`;
    }

    sql += `\nFETCH FIRST ${limit} ROWS ONLY`;

    return sql;
}
