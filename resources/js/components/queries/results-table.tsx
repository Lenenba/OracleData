import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Download,
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

function Cell({ value }: { value: unknown }) {
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
                    <ResultsTable items={nested} />
                </div>
            </details>
        );
    }

    return <>{formatScalar(value)}</>;
}

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

type ResultsTableProps = {
    items: Row[];
    /** Ordre de colonnes imposé (mode agent) ; sinon déduit des clés. */
    columns?: string[];
    /** Afficher les contrôles de recherche et d'export. */
    showControls?: boolean;
};

/**
 * Tableau générique avec tri par colonne, filtre texte et export CSV.
 * Les ressources enfants (`expand`) sont rendues en sous-tableaux dépliables.
 */
export function ResultsTable({
    items,
    columns,
    showControls = true,
}: ResultsTableProps) {
    const resolvedColumns = useMemo(
        () =>
            columns && columns.length > 0
                ? columns
                : Array.from(
                      new Set(items.flatMap((item) => Object.keys(item))),
                  ),
        [items, columns],
    );

    const [filter, setFilter] = useState('');
    const [sortCol, setSortCol] = useState<string | null>(null);
    const [sortDir, setSortDir] = useState<SortDir>(null);

    const filteredItems = useMemo(() => {
        if (!filter.trim()) {
            return items;
        }

        const lc = filter.toLowerCase();

        return items.filter((row) =>
            resolvedColumns.some((col) =>
                formatScalar(row[col]).toLowerCase().includes(lc),
            ),
        );
    }, [items, filter, resolvedColumns]);

    const sortedItems = useMemo(() => {
        if (!sortCol || !sortDir) {
            return filteredItems;
        }

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
                cell: (item) => <Cell value={item[column]} />,
            })),
        [resolvedColumns, sortCol, sortDir, toggleSort],
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
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => exportCsv(resolvedColumns, sortedItems)}
                        disabled={sortedItems.length === 0}
                    >
                        <Download className="mr-1.5 size-3.5" />
                        Exporter CSV ({sortedItems.length})
                    </Button>
                </div>
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
