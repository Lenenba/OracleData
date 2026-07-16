import { Head, Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

type QueryRow = {
    id: number;
    name: string;
    description: string | null;
    resource_path: string | null;
    mode: 'single' | 'agent';
    tenant: {
        key: string | null;
        label: string | null;
    };
    visibility: 'private' | 'shared';
    owner: string;
    can: { update: boolean };
};

function VisibilityToggle({
    query,
    onToggle,
}: {
    query: QueryRow;
    onToggle: (id: number, newVisibility: 'private' | 'shared') => void;
}) {
    const [loading, setLoading] = useState(false);

    async function toggle() {
        setLoading(true);
        const next: 'private' | 'shared' =
            query.visibility === 'shared' ? 'private' : 'shared';

        try {
            await fetch(queries.visibility.url(query.id), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ visibility: next }),
            });
            onToggle(query.id, next);
        } finally {
            setLoading(false);
        }
    }

    if (!query.can.update) {
        return (
            <Badge
                variant={
                    query.visibility === 'shared' ? 'default' : 'secondary'
                }
            >
                {query.visibility === 'shared' ? 'Partagée' : 'Privée'}
            </Badge>
        );
    }

    return (
        <button
            type="button"
            onClick={toggle}
            disabled={loading}
            className="inline-flex items-center gap-1"
            title="Cliquez pour changer la visibilité"
        >
            {loading ? (
                <Spinner className="size-3" />
            ) : (
                <Badge
                    variant={
                        query.visibility === 'shared' ? 'default' : 'secondary'
                    }
                    className="cursor-pointer hover:opacity-80"
                >
                    {query.visibility === 'shared' ? 'Partagée' : 'Privée'}
                </Badge>
            )}
        </button>
    );
}

export default function QueriesIndex({
    queries: initialRows,
}: {
    queries: QueryRow[];
}) {
    const [rows, setRows] = useState<QueryRow[]>(initialRows);
    const [search, setSearch] = useState('');

    const filtered = rows.filter(
        (q) =>
            q.name.toLowerCase().includes(search.toLowerCase()) ||
            (q.description ?? '').toLowerCase().includes(search.toLowerCase()),
    );

    function handleVisibilityToggle(
        id: number,
        newVisibility: 'private' | 'shared',
    ) {
        setRows((prev) =>
            prev.map((q) =>
                q.id === id ? { ...q, visibility: newVisibility } : q,
            ),
        );
    }

    function deleteQuery(id: number) {
        if (!confirm('Supprimer cette requête ? Cette action est irréversible.')) {
            return;
        }

        router.delete(queries.destroy(id));
    }

    return (
        <>
            <Head title="Requêtes" />

            <div className="space-y-6 px-4 py-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Bibliothèque de requêtes"
                        description="Vos requêtes enregistrées et celles partagées avec vous."
                    />
                    <Button asChild>
                        <Link href={queries.create()}>
                            <Plus className="size-4" />
                            Nouvelle requête
                        </Link>
                    </Button>
                </div>

                {rows.length > 0 && (
                    <Input
                        placeholder="Rechercher par nom ou description…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                )}

                {filtered.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <p className="text-sm text-muted-foreground">
                            {rows.length === 0
                                ? 'Aucune requête pour l\'instant.'
                                : 'Aucune requête ne correspond à votre recherche.'}
                        </p>
                        {rows.length === 0 && (
                            <Button asChild className="mt-4" variant="outline">
                                <Link href={queries.create()}>
                                    Créer ma première requête
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Nom
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Chemin REST
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Tenant
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Visibilité
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Propriétaire
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {filtered.map((query) => (
                                    <tr
                                        key={query.id}
                                        className="cursor-pointer hover:bg-muted/40"
                                        onClick={() =>
                                            router.visit(
                                                queries.show(query.id),
                                            )
                                        }
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {query.name}
                                            </div>
                                            {query.description && (
                                                <div className="text-xs text-muted-foreground">
                                                    {query.description}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {query.mode === 'agent' ? (
                                                <Badge variant="secondary">
                                                    Analyse multi-ressources
                                                </Badge>
                                            ) : (
                                                <code className="text-xs">
                                                    {query.resource_path}
                                                </code>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline">
                                                {query.tenant.label ??
                                                    query.tenant.key ??
                                                    '-'}
                                            </Badge>
                                        </td>
                                        <td
                                            className="px-4 py-3"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <VisibilityToggle
                                                query={query}
                                                onToggle={
                                                    handleVisibilityToggle
                                                }
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {query.owner}
                                        </td>
                                        <td
                                            className="px-4 py-3 text-right"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <div className="flex items-center justify-end gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={queries.show(
                                                            query.id,
                                                        )}
                                                    >
                                                        Exécuter
                                                    </Link>
                                                </Button>
                                                {query.can.update && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-destructive hover:text-destructive"
                                                        onClick={() =>
                                                            deleteQuery(query.id)
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

QueriesIndex.layout = {
    breadcrumbs: [{ title: 'Requêtes', href: queries.index() }],
};
