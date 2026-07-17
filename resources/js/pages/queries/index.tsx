import { Head, Link, router } from '@inertiajs/react';
import {
    Copy,
    Database,
    Eye,
    FileText,
    MoreHorizontal,
    Pencil,
    Plus,
    PlayCircle,
    Search,
    Server,
    Trash2,
    User,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { DataTable, StopClick, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import { EntityChip } from '@/components/entity-chip';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    can: { update: boolean; clone: boolean };
};

type QueryScope = 'all' | 'mine' | 'shared';

type QuerySummary = {
    all: number;
    mine: number;
    shared: number;
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

function QueryActionsMenu({
    query,
    onClone,
    onDelete,
}: {
    query: QueryRow;
    onClone: (id: number) => void;
    onDelete: (id: number) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={`Actions pour ${query.name}`}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem asChild className="cursor-pointer">
                    <Link href={queries.show(query.id)}>
                        <PlayCircle className="size-4" />
                        Exécuter
                    </Link>
                </DropdownMenuItem>

                {query.can.clone && (
                    <DropdownMenuItem
                        className="cursor-pointer"
                        onSelect={(event) => {
                            event.preventDefault();
                            onClone(query.id);
                        }}
                    >
                        <Copy className="size-4" />
                        Cloner
                    </DropdownMenuItem>
                )}

                {query.can.update && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild className="cursor-pointer">
                            <Link href={queries.edit(query.id)}>
                                <Pencil className="size-4" />
                                Modifier
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            className="cursor-pointer text-destructive focus:text-destructive"
                            onSelect={(event) => {
                                event.preventDefault();
                                onDelete(query.id);
                            }}
                        >
                            <Trash2 className="size-4 text-destructive" />
                            Supprimer
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function QueriesIndex({
    queries: initialRows,
    scope = 'all',
    summary,
}: {
    queries: QueryRow[];
    scope?: QueryScope;
    summary: QuerySummary;
}) {
    const [rows, setRows] = useState<QueryRow[]>(initialRows);
    const [search, setSearch] = useState('');

    useEffect(() => {
        setRows(initialRows);
    }, [initialRows]);

    const filtered = useMemo(() => {
        const q = search.toLowerCase();

        return rows.filter(
            (row) =>
                row.name.toLowerCase().includes(q) ||
                (row.description ?? '').toLowerCase().includes(q),
        );
    }, [rows, search]);

    const heading =
        scope === 'shared'
            ? {
                  title: 'Requêtes partagées',
                  description:
                      'Les requêtes mises à disposition de la plateforme. Clonez-les pour créer votre propre version modifiable.',
              }
            : {
                  title: 'Bibliothèque de requêtes',
                  description:
                      'Vos requêtes enregistrées et celles partagées avec vous.',
              };

    const scopeLinks = [
        {
            value: 'all' as const,
            label: 'Toutes',
            count: summary.all,
            href: queries.index(),
        },
        {
            value: 'mine' as const,
            label: 'Mes requêtes',
            count: summary.mine,
            href: queries.index({ query: { scope: 'mine' } }),
        },
        {
            value: 'shared' as const,
            label: 'Partagées',
            count: summary.shared,
            href: queries.shared(),
        },
    ];

    function handleVisibilityToggle(
        id: number,
        newVisibility: 'private' | 'shared',
    ) {
        setRows((prev) =>
            prev
                .map((q) =>
                    q.id === id ? { ...q, visibility: newVisibility } : q,
                )
                .filter(
                    (q) =>
                        scope !== 'shared' ||
                        q.visibility === 'shared' ||
                        q.id !== id,
                ),
        );
    }

    function cloneQuery(id: number) {
        router.post(queries.clone(id));
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
            width: 'w-24',
            cell: (query) => (
                <StopClick>
                    <QueryActionsMenu
                        query={query}
                        onClone={cloneQuery}
                        onDelete={deleteQuery}
                    />
                </StopClick>
            ),
        },
    ];

    return (
        <>
            <Head title="Requêtes" />

            <div className="px-6 py-6">
                <Heading
                    title={heading.title}
                    description={heading.description}
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

                    <div className="flex flex-wrap gap-2 border-b px-5 py-3">
                        {scopeLinks.map((item) => (
                            <Button
                                key={item.value}
                                asChild
                                size="sm"
                                variant={
                                    scope === item.value ? 'default' : 'outline'
                                }
                            >
                                <Link href={item.href}>
                                    {item.label}
                                    <span className="rounded-sm bg-background/20 px-1.5 py-0.5 text-[11px]">
                                        {item.count}
                                    </span>
                                </Link>
                            </Button>
                        ))}
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
