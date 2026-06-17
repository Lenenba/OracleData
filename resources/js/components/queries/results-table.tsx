type Row = Record<string, unknown>;

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

/**
 * Generic table that derives its columns from the union of keys across the
 * returned objects — the shape of the Fusion JSON varies by resource.
 */
export function ResultsTable({ items }: { items: Row[] }) {
    const columns = Array.from(
        new Set(items.flatMap((item) => Object.keys(item))),
    );

    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-left text-sm">
                <thead className="border-b bg-muted/50 text-muted-foreground">
                    <tr>
                        {columns.map((column) => (
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
                            {columns.map((column) => (
                                <td
                                    key={column}
                                    className="px-4 py-2 align-top whitespace-nowrap"
                                >
                                    {formatValue(item[column])}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
