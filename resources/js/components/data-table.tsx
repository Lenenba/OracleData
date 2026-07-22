import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/**
 * Table façon Preline : grille de filets fins dans les deux sens (colonnes
 * séparées par des bordures verticales, lignes par des horizontales), en-têtes
 * discrets à icône en casse normale, lignes aérées. La `border-collapse`
 * fusionne les bordures adjacentes en un quadrillage net de 1px.
 */

export type DataTableColumn<T> = {
    key: string;
    header: ReactNode;
    icon?: LucideIcon;
    align?: 'left' | 'right';
    /** Classe de largeur optionnelle (ex. « w-12 »). */
    width?: string;
    headerClassName?: string;
    cellClassName?: string;
    verticalAlign?: 'middle' | 'top';
    onHeaderClick?: () => void;
    cell: (row: T) => ReactNode;
};

/**
 * Cercle d'initiale (avatar sans photo, comme les lignes Preline) pour la
 * colonne principale d'une entité.
 */
export function TableAvatar({ label }: { label: string }) {
    const initial = label.trim().charAt(0).toUpperCase() || '?';

    return (
        <span className="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
            {initial}
        </span>
    );
}

/**
 * Empêche le clic d'une cellule interactive (bouton, toggle) de déclencher le
 * `onRowClick` de la ligne.
 */
export function StopClick({ children }: { children: ReactNode }) {
    return (
        <span
            onClick={(e) => e.stopPropagation()}
            className="inline-flex items-center gap-2"
        >
            {children}
        </span>
    );
}

/** Options disponibles dans le sélecteur de lignes par page. */
const PAGE_SIZE_OPTIONS = [10, 25, 50, 100] as const;

/** Valeur par défaut si `defaultPageSize` n'est pas précisé. */
const DEFAULT_PAGE_SIZE = 25;

export type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T, index: number) => string | number;
    onRowClick?: (row: T) => void;
    empty?: ReactNode;
    /**
     * Active la pagination côté client avec un sélecteur de lignes par page.
     * Si omis ou `false`, toutes les lignes sont affichées (comportement précédent).
     */
    paginated?: boolean;
    /**
     * Nombre de lignes initialement affichées (doit faire partie de
     * PAGE_SIZE_OPTIONS, sinon arrondi à la valeur la plus proche).
     * Par défaut : 25.
     */
    defaultPageSize?: number;
    /**
     * Libellés i18n pour le footer de pagination.
     * Si omis, le footer utilise les labels français par défaut.
     */
    paginationLabels?: {
        rowsPerPage?: string;   // « Lignes par page »
        of?: string;            // « sur »
        previous?: string;      // « Précédent »
        next?: string;          // « Suivant »
    };
};

export function DataTable<T>({
    columns,
    rows,
    rowKey,
    onRowClick,
    empty,
    paginated = false,
    defaultPageSize = DEFAULT_PAGE_SIZE,
    paginationLabels,
}: DataTableProps<T>) {
    const [pageSize, setPageSize] = useState<number>(() => {
        // S'assurer que la valeur initiale est dans les options
        const opts = PAGE_SIZE_OPTIONS as readonly number[];
        return opts.includes(defaultPageSize) ? defaultPageSize : DEFAULT_PAGE_SIZE;
    });
    const [page, setPage] = useState(1);

    // Quand les rows changent (ex. filtre amont), revenir à la page 1
    const totalRows = rows.length;

    const visibleRows = useMemo(() => {
        if (!paginated) return rows;
        const start = (page - 1) * pageSize;
        return rows.slice(start, start + pageSize);
    }, [paginated, rows, page, pageSize]);

    const totalPages = paginated ? Math.max(1, Math.ceil(totalRows / pageSize)) : 1;

    // Si le filtre amont réduit les rows, s'assurer d'être dans les bornes
    const safePage = Math.min(page, totalPages);
    if (safePage !== page) {
        setPage(safePage);
    }

    const from = paginated && totalRows > 0 ? (safePage - 1) * pageSize + 1 : (totalRows > 0 ? 1 : 0);
    const to = paginated ? Math.min(safePage * pageSize, totalRows) : totalRows;

    const labels = {
        rowsPerPage: paginationLabels?.rowsPerPage ?? 'Lignes par page',
        of: paginationLabels?.of ?? 'sur',
        previous: paginationLabels?.previous ?? 'Précédent',
        next: paginationLabels?.next ?? 'Suivant',
    };

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse text-sm">
                <thead>
                    <tr>
                        {columns.map((col) => {
                            const HeaderIcon = col.icon;
                            const headerContent = (
                                <>
                                    {HeaderIcon && (
                                        <HeaderIcon className="size-3.5 text-muted-foreground/60" />
                                    )}
                                    {col.header}
                                </>
                            );
                            const headerLayout = [
                                'inline-flex items-center gap-1.5',
                                col.align === 'right' ? 'flex-row-reverse' : '',
                            ].join(' ');

                            return (
                                <th
                                    key={col.key}
                                    className={[
                                        'border-b border-border bg-muted/30 px-4 py-3 align-middle font-medium whitespace-nowrap text-muted-foreground',
                                        'not-first:border-l',
                                        col.align === 'right'
                                            ? 'text-right'
                                            : 'text-left',
                                        col.onHeaderClick
                                            ? 'hover:bg-muted/60'
                                            : '',
                                        col.width ?? '',
                                        col.headerClassName ?? '',
                                    ].join(' ')}
                                >
                                    {col.onHeaderClick ? (
                                        <button
                                            type="button"
                                            onClick={col.onHeaderClick}
                                            className={[
                                                headerLayout,
                                                'w-full cursor-pointer font-medium text-inherit',
                                                col.align === 'right'
                                                    ? 'justify-end'
                                                    : 'justify-start',
                                            ].join(' ')}
                                        >
                                            {headerContent}
                                        </button>
                                    ) : (
                                        <span className={headerLayout}>
                                            {headerContent}
                                        </span>
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody className="[&>tr:last-child>td]:border-b-0">
                    {visibleRows.length === 0 ? (
                        <tr>
                            <td
                                colSpan={columns.length}
                                className="px-4 py-16 text-center text-sm text-muted-foreground"
                            >
                                {empty ?? 'Aucune donnée.'}
                            </td>
                        </tr>
                    ) : (
                        visibleRows.map((row, index) => (
                            <tr
                                key={rowKey(row, (safePage - 1) * pageSize + index)}
                                onClick={
                                    onRowClick
                                        ? () => onRowClick(row)
                                        : undefined
                                }
                                className={[
                                    'transition-colors',
                                    onRowClick
                                        ? 'cursor-pointer hover:bg-muted/40'
                                        : 'hover:bg-muted/25',
                                ].join(' ')}
                            >
                                {columns.map((col) => (
                                    <td
                                        key={col.key}
                                        className={[
                                            'border-b border-border px-4 py-3.5 whitespace-nowrap',
                                            col.verticalAlign === 'top'
                                                ? 'align-top'
                                                : 'align-middle',
                                            'not-first:border-l',
                                            col.align === 'right'
                                                ? 'text-right'
                                                : 'text-left',
                                            col.cellClassName ?? '',
                                        ].join(' ')}
                                    >
                                        {col.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>

            {/* Footer de pagination — visible seulement si paginated=true */}
            {paginated && (
                <div className="flex items-center justify-between gap-4 border-t bg-muted/10 px-4 py-2.5 text-xs text-muted-foreground">
                    {/* Sélecteur lignes par page */}
                    <div className="flex items-center gap-2">
                        <span className="whitespace-nowrap">{labels.rowsPerPage}</span>
                        <Select
                            value={String(pageSize)}
                            onValueChange={(v) => {
                                setPageSize(Number(v));
                                setPage(1);
                            }}
                        >
                            <SelectTrigger className="h-7 w-[70px] text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {PAGE_SIZE_OPTIONS.map((n) => (
                                    <SelectItem key={n} value={String(n)} className="text-xs">
                                        {n}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Info position */}
                    <span className="shrink-0">
                        {from}–{to} {labels.of} {totalRows}
                    </span>

                    {/* Boutons précédent / suivant */}
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            disabled={safePage <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            aria-label={labels.previous}
                            className="flex size-7 items-center justify-center rounded border bg-background transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <ChevronLeft className="size-3.5" />
                        </button>
                        <span className="px-1 tabular-nums">
                            {safePage} / {totalPages}
                        </span>
                        <button
                            type="button"
                            disabled={safePage >= totalPages}
                            onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                            aria-label={labels.next}
                            className="flex size-7 items-center justify-center rounded border bg-background transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <ChevronRight className="size-3.5" />
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
