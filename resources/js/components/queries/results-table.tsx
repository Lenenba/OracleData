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

type ResultsTableProps = {
    items: Row[];
    /** Ordre de colonnes imposé (mode agent) ; sinon déduit des clés. */
    columns?: string[];
};

/**
 * Tableau générique : déduit ses colonnes de l'union des clés des objets
 * (la forme du JSON Fusion varie par ressource), et rend les ressources
 * enfants (`expand`) en sous-tableaux dépliables.
 */
export function ResultsTable({ items, columns }: ResultsTableProps) {
    const resolvedColumns =
        columns && columns.length > 0
            ? columns
            : Array.from(new Set(items.flatMap((item) => Object.keys(item))));

    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-left text-sm">
                <thead className="border-b bg-muted/50 text-muted-foreground">
                    <tr>
                        {resolvedColumns.map((column) => (
                            <th
                                key={column}
                                className="px-4 py-2 font-medium whitespace-nowrap"
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {items.map((item, rowIndex) => (
                        <tr key={rowIndex} className="hover:bg-muted/40">
                            {resolvedColumns.map((column) => (
                                <td
                                    key={column}
                                    className="px-4 py-2 align-top whitespace-nowrap"
                                >
                                    <Cell value={item[column]} />
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
