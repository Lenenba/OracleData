import { Head, Link, router } from '@inertiajs/react';
import {
    Database,
    Eye,
    FileText,
    Pencil,
    Plus,
    Search,
    Server,
    Trash2,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { DataTable, StopClick, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import { EntityChip } from '@/components/entity-chip';
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

    const badge = (
        <Badge
            variant={query.visibility === 'shared' ? 'default' : 'secondary'}
        >
            {query.visibility === 'shared' ? 'Partagée' : 'Privée'}
        </Badge>
    );

    if (!query.can.update) {
        return badge;
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
                <span className="cursor-pointer transition-opacity hover:opacity-80">
                    {badge}
                </span>
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
        if (
            !confirm('Supprimer cette requête ? Cette action est irréversible.')
        ) {
            return;
        }

        router.delete(queries.destroy(id));
    }

    const columns: DataTableColumn<QueryRow>[] = [
        {
            key: 'name',
            header: 'Nom',
            icon: FileText,
            cell: (query) => (
                <div className="flex items-center gap-3">
                    <TableAvatar label={query.name} />
                    <div className="min-w-0">
                        <Link
                            href={queries.show(query.id)}
                            onClick={(e) => e.stopPropagation()}
                            className="font-medium text-foreground underline-offset-2 hover:underline"
                        >
                            {query.name}
                        </Link>
                        {query.description && (
                            <p className="max-w-xs truncate text-xs text-muted-foreground">
                                {query.description}
                            </p>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'resource',
            header: 'Ressource',
            icon: Server,
            cell: (query) =>
                query.mode === 'agent' ? (
                    <Badge variant="secondary">Analyse multi-ressources</Badge>
                ) : (
                    <code className="text-xs text-muted-foreground">
                        {query.resource_path}
                    </code>
                ),
        },
        {
            key: 'tenant',
            header: 'Tenant',
            icon: Database,
            cell: (query) =>
                (query.tenant.label ?? query.tenant.key) ? (
                    <EntityChip
                        icon={Server}
                        label={query.tenant.label ?? query.tenant.key ?? ''}
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'visibility',
            header: 'Visibilité',
            icon: Eye,
            cell: (query) => (
                <StopClick>
                    <VisibilityToggle
                        query={query}
                        onToggle={handleVisibilityToggle}
                    />
                </StopClick>
            ),
        },
        {
            key: 'owner',
            header: 'Propriétaire',
            icon: User,
            cellClassName: 'text-muted-foreground',
            cell: (query) => query.owner,
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            cell: (query) => (
                <StopClick>
                    <Button asChild size="sm" variant="outline">
                        <Link href={queries.show(query.id)}>Exécuter</Link>
                    </Button>
                    {query.can.update && (
                        <>
                            <Button asChild size="sm" variant="ghost">
                                <Link href={queries.edit(query.id)}>
                                    <Pencil className="size-4" />
                                </Link>
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                onClick={() => deleteQuery(query.id)}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </>
                    )}
                </StopClick>
            ),
        },
    ];

    return (
        <>
            <Head title="Requêtes" />

            <div className="px-6 py-6">
                <Heading
                    title="Bibliothèque de requêtes"
                    description="Vos requêtes enregistrées et celles partagées avec vous."
                />

                <div className="overflow-hidden rounded-xl border bg-card">
                    {/* Toolbar */}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                        <div className="relative w-full max-w-xs">
                            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Rechercher…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="h-9 pl-9"
                                disabled={rows.length === 0}
                            />
                        </div>
                        <Button asChild size="sm">
                            <Link href={queries.create()}>
                                <Plus className="size-4" />
                                Nouvelle requête
                            </Link>
                        </Button>
                    </div>

                    <DataTable
                        columns={columns}
                        rows={filtered}
                        rowKey={(query) => query.id}
                        onRowClick={(query) =>
                            router.visit(queries.show(query.id))
                        }
                        empty={
                            rows.length === 0 ? (
                                <div className="space-y-3">
                                    <p>Aucune requête pour l'instant.</p>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={queries.create()}>
                                            Créer ma première requête
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                'Aucune requête ne correspond à votre recherche.'
                            )
                        }
                    />

                    {/* Footer */}
                    <div className="border-t px-5 py-3 text-xs text-muted-foreground">
                        {filtered.length} résultat
                        {filtered.length > 1 ? 's' : ''}
                        {search && ` sur ${rows.length}`}
                    </div>
                </div>
            </div>
        </>
    );
}

QueriesIndex.layout = {
    breadcrumbs: [{ title: 'Requêtes', href: queries.index() }],
};
