import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

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

export function DataTable<T>({
    columns,
    rows,
    rowKey,
    onRowClick,
    empty,
}: {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T, index: number) => string | number;
    onRowClick?: (row: T) => void;
    empty?: ReactNode;
}) {
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
                    {rows.length === 0 ? (
                        <tr>
                            <td
                                colSpan={columns.length}
                                className="px-4 py-16 text-center text-sm text-muted-foreground"
                            >
                                {empty ?? 'Aucune donnée.'}
                            </td>
                        </tr>
                    ) : (
                        rows.map((row, index) => (
                            <tr
                                key={rowKey(row, index)}
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
        </div>
    );
}
