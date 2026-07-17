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
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
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

type PaginatedQueries = {
    data: QueryRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
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
            const response = await fetch(queries.visibility.url(query.id), {
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

            if (!response.ok) {
                throw new Error('Visibility update failed.');
            }

            onToggle(query.id, next);
        } catch {
            toast.error("La visibilité n'a pas pu être mise à jour.");
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
    queries: queryPage,
    scope = 'all',
    summary,
    search: initialSearch = '',
}: {
    queries: PaginatedQueries;
    scope?: QueryScope;
    summary: QuerySummary;
    search?: string;
}) {
    const [visibilityOverrides, setVisibilityOverrides] = useState<
        Record<number, 'private' | 'shared'>
    >({});
    const [search, setSearch] = useState(initialSearch);
    const rows = queryPage.data
        .map((query) => ({
            ...query,
            visibility: visibilityOverrides[query.id] ?? query.visibility,
        }))
        .filter((query) => scope !== 'shared' || query.visibility === 'shared');

    useEffect(() => {
        if (search === initialSearch) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(
                queries.index({
                    query: {
                        scope: scope === 'all' ? undefined : scope,
                        search: search.trim() || undefined,
                    },
                }),
                {},
                {
                    only: ['queries', 'search'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        }, 350);

        return () => window.clearTimeout(timer);
    }, [initialSearch, scope, search]);

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
        setVisibilityOverrides((current) => ({
            ...current,
            [id]: newVisibility,
        }));
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
        ...(scope === 'shared'
            ? []
            : [
                  {
                      key: 'visibility',
                      header: 'Visibilité',
                      icon: Eye,
                      cell: (query: QueryRow) => (
                          <StopClick>
                              <VisibilityToggle
                                  query={query}
                                  onToggle={handleVisibilityToggle}
                              />
                          </StopClick>
                      ),
                  },
              ]),
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
                        rows={rows}
                        rowKey={(query) => query.id}
                        onRowClick={(query) =>
                            router.visit(queries.show(query.id))
                        }
                        empty={
                            queryPage.total === 0 && initialSearch === '' ? (
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
                    <div className="flex items-center justify-between gap-3 border-t px-5 py-3 text-xs text-muted-foreground">
                        <span>
                            {queryPage.total === 0 ? (
                                '0 résultat'
                            ) : (
                                <>
                                    {queryPage.from}–{queryPage.to} sur{' '}
                                    {queryPage.total}
                                </>
                            )}
                        </span>
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={queryPage.prev_page_url === null}
                                onClick={() => {
                                    if (queryPage.prev_page_url !== null) {
                                        router.visit(queryPage.prev_page_url, {
                                            only: ['queries'],
                                            preserveState: true,
                                            preserveScroll: true,
                                        });
                                    }
                                }}
                            >
                                Précédent
                            </Button>
                            <span>
                                Page {queryPage.current_page} sur{' '}
                                {queryPage.last_page}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={queryPage.next_page_url === null}
                                onClick={() => {
                                    if (queryPage.next_page_url !== null) {
                                        router.visit(queryPage.next_page_url, {
                                            only: ['queries'],
                                            preserveState: true,
                                            preserveScroll: true,
                                        });
                                    }
                                }}
                            >
                                Suivant
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

QueriesIndex.layout = {
    breadcrumbs: [{ title: 'Requêtes', href: queries.index() }],
};
