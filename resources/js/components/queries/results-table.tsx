import {
    AlignJustify,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Download,
    List,
    Search,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Row = Record<string, unknown>;

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Oracle `expand` renvoie les enfants soit comme un tableau, soit comme une
 * enveloppe `{ items: [...] }`. On récupère les lignes enfants dans les deux cas.
 */
function childRows(value: unknown): Row[] | null {
    if (Array.isArray(value) && value.every(isRecord)) {
        return value as Row[];
    }

    if (
        isRecord(value) &&
        Array.isArray(value.items) &&
        value.items.every(isRecord)
    ) {
        return value.items as Row[];
    }

    return null;
}

function formatScalar(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

// ─── Flatten logic ────────────────────────────────────────────────────────────

/**
 * Détecte les colonnes du premier item qui contiennent des sous-tableaux Oracle
 * (expand). Seules ces colonnes génèrent une expansion tabulaire.
 */
function detectChildColumns(items: Row[]): string[] {
    const first = items[0];
    if (!first) return [];
    return Object.keys(first).filter((k) => childRows(first[k]) !== null);
}

/**
 * Aplatit les items Oracle en mode tabulaire (SQL JOIN).
 *
 * Pour chaque ligne parent et chaque colonne enfant détectée :
 * - si l'enfant est vide → une ligne avec la valeur enfant à « — »
 * - si l'enfant a N lignes → N lignes, chacune fusionnant les scalaires
 *   du parent avec les colonnes de l'enfant préfixées par `childKey.`
 *
 * Les colonnes enfants brutes sont supprimées du parent dans la ligne aplatie.
 */
export function flattenItems(items: Row[], childColumnKeys: string[]): Row[] {
    if (childColumnKeys.length === 0) return items;

    return items.flatMap((parent) => {
        // Extraire les scalaires du parent (sans les colonnes enfants)
        const parentScalars: Row = {};
        for (const [k, v] of Object.entries(parent)) {
            if (!childColumnKeys.includes(k)) {
                parentScalars[k] = v;
            }
        }

        // Pour chaque colonne enfant, produire des lignes croisées.
        // On commence avec une seule ligne (le parent) et on la croise
        // successivement avec chaque enfant.
        let expanded: Row[] = [parentScalars];

        for (const childKey of childColumnKeys) {
            const children = childRows(parent[childKey]);

            if (!children || children.length === 0) {
                // Aucun enfant → conserver les lignes existantes, juste ajouter
                // une colonne vide pour signaler l'absence.
                expanded = expanded.map((row) => ({
                    ...row,
                    [`${childKey}._empty`]: true,
                }));
                continue;
            }

            // Croiser chaque ligne existante avec chaque ligne enfant
            const crossed: Row[] = [];
            for (const existingRow of expanded) {
                for (const child of children) {
                    const childPrefixed: Row = {};
                    for (const [ck, cv] of Object.entries(child)) {
                        childPrefixed[`${childKey}.${ck}`] = cv;
                    }
                    crossed.push({ ...existingRow, ...childPrefixed });
                }
            }
            expanded = crossed;
        }

        return expanded;
    });
}

/**
 * Colonnes d'un tableau aplati : scalaires du parent + toutes les colonnes
 * enfant préfixées par `childKey.` dans l'ordre de première apparition.
 */
function flatColumns(flatRows: Row[]): string[] {
    return Array.from(new Set(flatRows.flatMap((r) => Object.keys(r)))).filter(
        (c) => !c.endsWith('._empty'),
    );
}

// ─── CSV export ───────────────────────────────────────────────────────────────

/** Convertit les items en CSV et déclenche le téléchargement. */
function exportCsv(columns: string[], items: Row[], filename = 'export.csv') {
    const escape = (v: unknown) => {
        const s = formatScalar(v);

        return s.includes(',') || s.includes('"') || s.includes('\n')
            ? `"${s.replace(/"/g, '""')}"`
            : s;
    };

    const header = columns.map(escape).join(',');
    const lines = items.map((row) =>
        columns.map((col) => escape(row[col])).join(','),
    );

    const csv = [header, ...lines].join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], {
        type: 'text/csv;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

// ─── UI helpers ───────────────────────────────────────────────────────────────

type SortDir = 'asc' | 'desc' | null;

function SortIndicator({ direction }: { direction: SortDir }) {
    if (direction === null) {
        return <ArrowUpDown className="ml-1 inline-block size-3 opacity-40" />;
    }

    return direction === 'asc' ? (
        <ArrowUp className="ml-1 inline-block size-3" />
    ) : (
        <ArrowDown className="ml-1 inline-block size-3" />
    );
}

function Cell({
    value,
    childColumns,
}: {
    value: unknown;
    /** Projection des colonnes de ce sous-tableau enfant, le cas échéant. */
    childColumns?: string[];
}) {
    const nested = childRows(value);

    if (nested !== null) {
        if (nested.length === 0) {
            return <span className="text-muted-foreground">—</span>;
        }

        return (
            <details className="group">
                <summary className="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                    {nested.length} élément(s)
                </summary>
                <div className="mt-2">
                    <ResultsTable
                        items={nested}
                        columns={
                            childColumns && childColumns.length > 0
                                ? childColumns
                                : undefined
                        }
                    />
                </div>
            </details>
        );
    }

    return <>{formatScalar(value)}</>;
}

// ─── ResultsTable ─────────────────────────────────────────────────────────────

export type DisplayMode = 'hierarchical' | 'flat';

type ResultsTableProps = {
    items: Row[];
    /** Ordre de colonnes imposé (mode agent) ; sinon déduit des clés. */
    columns?: string[];
    /** Colonnes visibles par sous-tableau enfant (projection client). */
    childColumns?: Record<string, string[]>;
    /** Afficher les contrôles de recherche et d'export. */
    showControls?: boolean;
    /** Mode d'affichage initial. Par défaut : hiérarchique. */
    defaultDisplayMode?: DisplayMode;
};

/**
 * Tableau générique avec tri par colonne, filtre texte et export CSV.
 *
 * **Mode hiérarchique (défaut)** : les ressources enfants (`expand`) sont rendues
 * en sous-tableaux dépliables dans leur cellule.
 *
 * **Mode tabulaire** : les sous-tableaux sont aplatis en lignes distinctes par
 * cross-join (comportement SQL JOIN). Les colonnes enfant sont préfixées par
 * `nomEnfant.` pour éviter les collisions.
 */
export function ResultsTable({
    items,
    columns,
    childColumns,
    showControls = true,
    defaultDisplayMode = 'hierarchical',
}: ResultsTableProps) {
    const [displayMode, setDisplayMode] =
        useState<DisplayMode>(defaultDisplayMode);
    const [filter, setFilter] = useState('');
    const [sortCol, setSortCol] = useState<string | null>(null);
    const [sortDir, setSortDir] = useState<SortDir>(null);

    // Détecter les colonnes enfant présentes dans les données
    const childColumnKeys = useMemo(() => detectChildColumns(items), [items]);
    const hasChildren = childColumnKeys.length > 0;

    // En mode tabulaire : aplatir les données
    const displayItems = useMemo(
        () =>
            displayMode === 'flat' && hasChildren
                ? flattenItems(items, childColumnKeys)
                : items,
        [displayMode, hasChildren, items, childColumnKeys],
    );

    const resolvedColumns = useMemo(() => {
        if (displayMode === 'flat' && hasChildren) {
            return flatColumns(displayItems);
        }

        return columns && columns.length > 0
            ? columns
            : Array.from(
                  new Set(items.flatMap((item) => Object.keys(item))),
              );
    }, [displayMode, hasChildren, displayItems, columns, items]);

    const filteredItems = useMemo(() => {
        if (!filter.trim()) return displayItems;
        const lc = filter.toLowerCase();
        return displayItems.filter((row) =>
            resolvedColumns.some((col) =>
                formatScalar(row[col]).toLowerCase().includes(lc),
            ),
        );
    }, [displayItems, filter, resolvedColumns]);

    const sortedItems = useMemo(() => {
        if (!sortCol || !sortDir) return filteredItems;
        return [...filteredItems].sort((a, b) => {
            const va = formatScalar(a[sortCol]);
            const vb = formatScalar(b[sortCol]);
            const cmp = va.localeCompare(vb, undefined, { numeric: true });
            return sortDir === 'asc' ? cmp : -cmp;
        });
    }, [filteredItems, sortCol, sortDir]);

    const toggleSort = useCallback(
        (col: string) => {
            if (sortCol !== col) {
                setSortCol(col);
                setSortDir('asc');
            } else if (sortDir === 'asc') {
                setSortDir('desc');
            } else {
                setSortCol(null);
                setSortDir(null);
            }
        },
        [sortCol, sortDir],
    );

    const tableColumns = useMemo<DataTableColumn<Row>[]>(
        () =>
            resolvedColumns.map((column) => ({
                key: column,
                header: (
                    <>
                        {column}
                        <SortIndicator
                            direction={sortCol === column ? sortDir : null}
                        />
                    </>
                ),
                headerClassName: 'cursor-pointer',
                verticalAlign: 'top',
                onHeaderClick: () => toggleSort(column),
                cell: (item) =>
                    displayMode === 'flat' ? (
                        // En mode plat, toutes les valeurs sont scalaires
                        <>{formatScalar(item[column])}</>
                    ) : (
                        <Cell
                            value={item[column]}
                            childColumns={childColumns?.[column]}
                        />
                    ),
            })),
        [
            resolvedColumns,
            sortCol,
            sortDir,
            toggleSort,
            childColumns,
            displayMode,
        ],
    );

    return (
        <div className="flex flex-col gap-3">
            {showControls && (
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative max-w-xs flex-1">
                        <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            className="pl-8 text-sm"
                            placeholder="Filtrer les résultats…"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        />
                    </div>

                    {/* Bascule mode d'affichage — visible seulement si des enfants existent */}
                    {hasChildren && (
                        <div className="flex items-center rounded-lg border bg-muted/40 p-0.5">
                            <button
                                type="button"
                                onClick={() => setDisplayMode('hierarchical')}
                                title="Mode hiérarchique — sous-tableaux dépliables"
                                className={[
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors',
                                    displayMode === 'hierarchical'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ].join(' ')}
                            >
                                <List className="size-3.5" />
                                Hiérarchique
                            </button>
                            <button
                                type="button"
                                onClick={() => setDisplayMode('flat')}
                                title="Mode tabulaire — une ligne par sous-enregistrement"
                                className={[
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors',
                                    displayMode === 'flat'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ].join(' ')}
                            >
                                <AlignJustify className="size-3.5" />
                                Tabulaire
                            </button>
                        </div>
                    )}

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            exportCsv(resolvedColumns, sortedItems)
                        }
                        disabled={sortedItems.length === 0}
                    >
                        <Download className="mr-1.5 size-3.5" />
                        Exporter CSV ({sortedItems.length})
                    </Button>
                </div>
            )}

            {/* Info ligne count en mode plat */}
            {displayMode === 'flat' && hasChildren && sortedItems.length !== items.length && (
                <p className="text-xs text-muted-foreground">
                    {sortedItems.length} ligne(s) après expansion ({items.length} enregistrement(s) source)
                </p>
            )}

            <div className="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    columns={tableColumns}
                    rows={sortedItems}
                    rowKey={(_, rowIndex) => rowIndex}
                    empty="Aucun résultat pour ce filtre."
                />
            </div>
        </div>
    );
}
