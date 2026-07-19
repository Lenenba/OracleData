import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    BookmarkPlus,
    Copy,
    Database,
    Eye,
    FileText,
    FolderOpen,
    MoreHorizontal,
    Pencil,
    Pin,
    Plus,
    PlayCircle,
    Search,
    Server,
    Share2,
    Star,
    Tags,
    Trash2,
    User,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { DataTable, StopClick, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import { EntityChip } from '@/components/entity-chip';
import Heading from '@/components/heading';
import { QueryAccessLevelBadge } from '@/components/queries/query-access-level-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';
import savedQueryViews from '@/routes/saved-query-views';
import type {
    QueryAccessLevel,
    QueryCapabilities,
} from '@/types/query-sharing';

type QueryCategory = {
    slug: string;
    name: string;
    color: string | null;
};

type QueryTag = {
    name: string;
    slug: string;
};

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
    access_level: QueryAccessLevel;
    owner: string;
    category: QueryCategory | null;
    tags: QueryTag[];
    preference: { is_favorite: boolean; is_pinned: boolean };
    statistics: {
        execution_count: number;
        success_rate: number | null;
        last_executed_at: string | null;
    };
    can: QueryCapabilities;
};

type CategoryOption = {
    id: number;
    slug: string;
    name: string;
    color: string | null;
};

type TagOption = {
    id: number;
    slug: string;
    name: string;
    label: string;
};

type QueryScope = 'all' | 'mine' | 'shared';

type QuerySummary = {
    all: number;
    mine: number;
    shared: number;
    favorites: number;
    pinned: number;
};

type QuerySort =
    | 'updated_desc'
    | 'updated_asc'
    | 'name_asc'
    | 'name_desc'
    | 'executions_desc'
    | 'last_executed_desc';

type LibraryFilters = {
    scope: QueryScope;
    search: string;
    category: string;
    tag: string;
    sort: QuerySort;
    favorite: boolean;
    pinned: boolean;
};

type SavedView = {
    id: number;
    name: string;
    filters: Partial<LibraryFilters>;
    is_default: boolean;
};

type UsageSummary = {
    total_executions: number;
    success_rate: number | null;
    last_executed_at: string | null;
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

function QueryActionsMenu({
    query,
    onClone,
    onDelete,
}: {
    query: QueryRow;
    onClone: (id: number) => void;
    onDelete: (id: number) => void;
}) {
    const { t } = useI18n();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={t('queries.actionLabel', { name: query.name })}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem asChild className="cursor-pointer">
                    <Link href={queries.show(query.id)}>
                        {query.can.execute ? (
                            <PlayCircle className="size-4" />
                        ) : (
                            <Eye className="size-4" />
                        )}
                        {query.can.execute
                            ? t('queries.run')
                            : t('queries.viewDetails')}
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
                        {t('queries.clone')}
                    </DropdownMenuItem>
                )}

                {(query.can.update || query.can.manage_sharing) && (
                    <>
                        <DropdownMenuSeparator />
                        {query.can.update && (
                            <DropdownMenuItem
                                asChild
                                className="cursor-pointer"
                            >
                                <Link href={queries.edit(query.id)}>
                                    <Pencil className="size-4" />
                                    {t('queries.edit')}
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {query.can.manage_sharing && (
                            <DropdownMenuItem
                                asChild
                                className="cursor-pointer"
                            >
                                <Link href={queries.shares.index(query.id)}>
                                    <Share2 className="size-4" />
                                    {t('queries.manageSharing')}
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {query.can.update && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    className="cursor-pointer text-destructive focus:text-destructive"
                                    onSelect={(event) => {
                                        event.preventDefault();
                                        onDelete(query.id);
                                    }}
                                >
                                    <Trash2 className="size-4 text-destructive" />
                                    {t('queries.delete')}
                                </DropdownMenuItem>
                            </>
                        )}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function SaveViewDialog({
    open,
    onOpenChange,
    filters,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: LibraryFilters;
}) {
    const { t } = useI18n();
    const [name, setName] = useState('');
    const [isDefault, setIsDefault] = useState(false);
    const [saving, setSaving] = useState(false);

    function save() {
        if (name.trim() === '' || saving) {
            return;
        }

        setSaving(true);
        router.post(
            savedQueryViews.store.url(),
            {
                name: name.trim(),
                filters: {
                    scope: filters.scope,
                    search: filters.search || null,
                    category: filters.category || null,
                    tag: filters.tag || null,
                    sort: filters.sort,
                    favorite: filters.favorite || null,
                    pinned: filters.pinned || null,
                },
                is_default: isDefault,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setName('');
                    setIsDefault(false);
                    onOpenChange(false);
                },
                onError: () => setSaving(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('queries.saveView')}</DialogTitle>
                    <DialogDescription>
                        {t('queries.saveViewDescription')}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <label
                        htmlFor="saved-view-name"
                        className="text-sm font-medium"
                    >
                        {t('queries.viewName')}
                    </label>
                    <Input
                        id="saved-view-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                save();
                            }
                        }}
                        maxLength={100}
                        autoFocus
                    />
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={isDefault}
                        onCheckedChange={(checked) =>
                            setIsDefault(checked === true)
                        }
                    />
                    {t('queries.defaultView')}
                </label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        disabled={name.trim() === '' || saving}
                    >
                        {saving ? t('common.saving') : t('queries.saveView')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function QueriesIndex({
    queries: queryPage,
    scope = 'all',
    summary,
    search: initialSearch = '',
    category: activeCategory = '',
    tag: activeTag = '',
    sort = 'updated_desc',
    favorite = false,
    pinned = false,
    categories = [],
    tags = [],
    usage,
    savedViews = [],
}: {
    queries: PaginatedQueries;
    scope?: QueryScope;
    summary: QuerySummary;
    search?: string;
    category?: string;
    tag?: string;
    sort?: QuerySort;
    favorite?: boolean;
    pinned?: boolean;
    categories?: CategoryOption[];
    tags?: TagOption[];
    usage: UsageSummary;
    savedViews?: SavedView[];
}) {
    const { t, formatDate, formatNumber } = useI18n();
    const [preferenceOverrides, setPreferenceOverrides] = useState<
        Record<number, QueryRow['preference']>
    >({});
    const [preferencePending, setPreferencePending] = useState<Set<number>>(
        () => new Set(),
    );
    const [saveViewOpen, setSaveViewOpen] = useState(false);
    const [search, setSearch] = useState(initialSearch);
    const currentFilters: LibraryFilters = {
        scope,
        search,
        category: activeCategory,
        tag: activeTag,
        sort,
        favorite,
        pinned,
    };
    const rows = queryPage.data.map((query) => ({
        ...query,
        preference: preferenceOverrides[query.id] ?? query.preference,
    }));
    const hasActiveLibraryFilters =
        scope !== 'all' ||
        initialSearch.trim() !== '' ||
        activeCategory !== '' ||
        activeTag !== '' ||
        favorite ||
        pinned;

    function filterUrl(next: Partial<LibraryFilters> = {}) {
        const filters = { ...currentFilters, ...next };

        return queries.index({
            query: {
                scope: filters.scope === 'all' ? undefined : filters.scope,
                search: filters.search.trim() || undefined,
                category: filters.category || undefined,
                tag: filters.tag || undefined,
                sort:
                    filters.sort === 'updated_desc' ? undefined : filters.sort,
                favorite: filters.favorite || undefined,
                pinned: filters.pinned || undefined,
                view: 'none',
            },
        });
    }

    function applyFilters(next: Partial<LibraryFilters>) {
        router.get(
            filterUrl(next),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

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
                        category: activeCategory || undefined,
                        tag: activeTag || undefined,
                        sort: sort === 'updated_desc' ? undefined : sort,
                        favorite: favorite || undefined,
                        pinned: pinned || undefined,
                        view: 'none',
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
    }, [
        initialSearch,
        scope,
        search,
        activeCategory,
        activeTag,
        sort,
        favorite,
        pinned,
    ]);

    const heading =
        scope === 'shared'
            ? {
                  title: t('queries.shared'),
                  description: t('queries.sharedDescription'),
              }
            : {
                  title: t('queries.title'),
                  description: t('queries.description'),
              };

    const scopeLinks = [
        {
            value: 'all' as const,
            label: t('queries.all'),
            count: summary.all,
            href: filterUrl({ scope: 'all' }),
        },
        {
            value: 'mine' as const,
            label: t('queries.mine'),
            count: summary.mine,
            href: filterUrl({ scope: 'mine' }),
        },
        {
            value: 'shared' as const,
            label: t('queries.sharedBadge'),
            count: summary.shared,
            href: filterUrl({ scope: 'shared' }),
        },
    ];

    function cloneQuery(id: number) {
        router.post(queries.clone(id));
    }

    function deleteQuery(id: number) {
        if (!confirm(t('queries.deleteConfirm'))) {
            return;
        }

        router.delete(queries.destroy(id));
    }

    async function togglePreference(
        query: QueryRow,
        key: 'is_favorite' | 'is_pinned',
    ) {
        if (preferencePending.has(query.id)) {
            return;
        }

        const previous = preferenceOverrides[query.id] ?? query.preference;
        const next = { ...previous, [key]: !previous[key] };

        setPreferencePending((current) => {
            const pending = new Set(current);
            pending.add(query.id);

            return pending;
        });
        setPreferenceOverrides((current) => ({
            ...current,
            [query.id]: next,
        }));

        try {
            const response = await fetch(queries.preference.url(query.id), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ [key]: next[key] }),
            });

            if (!response.ok) {
                throw new Error('Preference update failed.');
            }

            router.reload({
                only: ['queries', 'summary'],
                onSuccess: () => {
                    setPreferenceOverrides((current) => {
                        const overrides = { ...current };
                        delete overrides[query.id];

                        return overrides;
                    });
                },
                onFinish: () => {
                    setPreferencePending((current) => {
                        const pending = new Set(current);
                        pending.delete(query.id);

                        return pending;
                    });
                },
            });
        } catch {
            setPreferenceOverrides((current) => ({
                ...current,
                [query.id]: previous,
            }));
            setPreferencePending((current) => {
                const pending = new Set(current);
                pending.delete(query.id);

                return pending;
            });
            toast.error(t('queries.preferenceError'));
        }
    }

    function applySavedView(view: SavedView) {
        router.get(
            queries.index({
                query: {
                    ...view.filters,
                    scope:
                        view.filters.scope === 'all'
                            ? undefined
                            : view.filters.scope,
                    view: 'none',
                },
            }),
            {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function deleteSavedView(view: SavedView) {
        if (!confirm(t('queries.deleteViewConfirm', { name: view.name }))) {
            return;
        }

        router.delete(savedQueryViews.destroy.url(view.id), {
            preserveScroll: true,
        });
    }

    const columns: DataTableColumn<QueryRow>[] = [
        {
            key: 'name',
            header: t('queries.name'),
            icon: FileText,
            cell: (query) => (
                <div className="flex items-center gap-3">
                    <StopClick>
                        <div className="flex shrink-0 items-center gap-0.5">
                            <button
                                type="button"
                                onClick={() =>
                                    void togglePreference(query, 'is_pinned')
                                }
                                className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                disabled={preferencePending.has(query.id)}
                                aria-label={
                                    query.preference.is_pinned
                                        ? t('queries.unpin')
                                        : t('queries.pin')
                                }
                                aria-pressed={query.preference.is_pinned}
                                title={
                                    query.preference.is_pinned
                                        ? t('queries.unpin')
                                        : t('queries.pin')
                                }
                            >
                                <Pin
                                    className="size-3.5"
                                    fill={
                                        query.preference.is_pinned
                                            ? 'currentColor'
                                            : 'none'
                                    }
                                />
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    void togglePreference(query, 'is_favorite')
                                }
                                className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-amber-500"
                                disabled={preferencePending.has(query.id)}
                                aria-label={
                                    query.preference.is_favorite
                                        ? t('queries.removeFavorite')
                                        : t('queries.addFavorite')
                                }
                                aria-pressed={query.preference.is_favorite}
                                title={
                                    query.preference.is_favorite
                                        ? t('queries.removeFavorite')
                                        : t('queries.addFavorite')
                                }
                            >
                                <Star
                                    className="size-3.5"
                                    fill={
                                        query.preference.is_favorite
                                            ? 'currentColor'
                                            : 'none'
                                    }
                                />
                            </button>
                        </div>
                    </StopClick>
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
            key: 'category',
            header: t('queries.category'),
            icon: FolderOpen,
            cell: (query) => (
                <div className="space-y-1">
                    {query.category ? (
                        <span className="inline-flex items-center gap-1.5 text-sm">
                            <span
                                aria-hidden="true"
                                className="inline-block size-2 rounded-full bg-muted-foreground"
                                style={
                                    query.category.color
                                        ? {
                                              backgroundColor:
                                                  query.category.color,
                                          }
                                        : undefined
                                }
                            />
                            {query.category.name}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    )}
                    {query.tags.length > 0 && (
                        <div className="flex flex-wrap gap-1">
                            {query.tags.map((tag) => (
                                <StopClick key={tag.slug}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            applyFilters({ tag: tag.slug })
                                        }
                                    >
                                        <Badge
                                            variant="outline"
                                            className="cursor-pointer text-[11px] hover:bg-accent"
                                        >
                                            {tag.name}
                                        </Badge>
                                    </button>
                                </StopClick>
                            ))}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'usage',
            header: t('queries.usage'),
            icon: BarChart3,
            cellClassName: 'text-muted-foreground',
            cell: (query) => (
                <div className="space-y-0.5 text-xs">
                    <p className="font-medium text-foreground">
                        {t('queries.executionCount', {
                            count: formatNumber(
                                query.statistics.execution_count,
                            ),
                        })}
                    </p>
                    <p>
                        {query.statistics.success_rate === null
                            ? t('queries.neverExecuted')
                            : t('queries.successRate', {
                                  rate: query.statistics.success_rate,
                              })}
                    </p>
                    {query.statistics.last_executed_at && (
                        <p>
                            {formatDate(query.statistics.last_executed_at, {
                                dateStyle: 'medium',
                            })}
                        </p>
                    )}
                </div>
            ),
        },
        {
            key: 'access_level',
            header: t('queries.accessLevel'),
            icon: Eye,
            cell: (query) => (
                <QueryAccessLevelBadge accessLevel={query.access_level} />
            ),
        },
        {
            key: 'owner',
            header: t('queries.owner'),
            icon: User,
            cellClassName: 'text-muted-foreground',
            cell: (query) => query.owner,
        },
        {
            key: 'actions',
            header: t('queries.actions'),
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
            <Head title={t('nav.queries')} />

            <div className="px-6 py-6">
                <Heading
                    title={heading.title}
                    description={heading.description}
                />

                <div className="overflow-hidden rounded-xl border bg-card">
                    {/* Toolbar */}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative w-full max-w-xs">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder={t('queries.search')}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="h-9 pl-9"
                                />
                            </div>
                            {categories.length > 0 && (
                                <Select
                                    value={activeCategory || 'all'}
                                    onValueChange={(value) =>
                                        applyFilters({
                                            category:
                                                value === 'all' ? '' : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-48"
                                        aria-label={t('queries.category')}
                                    >
                                        <FolderOpen className="size-4 text-muted-foreground" />
                                        <SelectValue
                                            placeholder={t(
                                                'queries.allCategories',
                                            )}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            {t('queries.allCategories')}
                                        </SelectItem>
                                        {categories.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={option.slug}
                                            >
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            {tags.length > 0 && (
                                <Select
                                    value={activeTag || 'all'}
                                    onValueChange={(value) =>
                                        applyFilters({
                                            tag: value === 'all' ? '' : value,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-44"
                                        aria-label={t('queries.tags')}
                                    >
                                        <Tags className="size-4 text-muted-foreground" />
                                        <SelectValue
                                            placeholder={t('queries.allTags')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            {t('queries.allTags')}
                                        </SelectItem>
                                        {tags.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={option.slug}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <Select
                                value={sort}
                                onValueChange={(value) =>
                                    applyFilters({ sort: value as QuerySort })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-44"
                                    aria-label={t('queries.sortLabel')}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="updated_desc">
                                        {t('queries.sortUpdatedDesc')}
                                    </SelectItem>
                                    <SelectItem value="updated_asc">
                                        {t('queries.sortUpdatedAsc')}
                                    </SelectItem>
                                    <SelectItem value="name_asc">
                                        {t('queries.sortNameAsc')}
                                    </SelectItem>
                                    <SelectItem value="name_desc">
                                        {t('queries.sortNameDesc')}
                                    </SelectItem>
                                    <SelectItem value="executions_desc">
                                        {t('queries.sortExecutions')}
                                    </SelectItem>
                                    <SelectItem value="last_executed_desc">
                                        {t('queries.sortLastExecuted')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                size="sm"
                                variant={favorite ? 'default' : 'outline'}
                                onClick={() =>
                                    applyFilters({ favorite: !favorite })
                                }
                            >
                                <Star className="size-3.5" />
                                {t('queries.favorites')}
                                <span className="text-[11px]">
                                    {summary.favorites}
                                </span>
                            </Button>
                            <Button
                                size="sm"
                                variant={pinned ? 'default' : 'outline'}
                                onClick={() =>
                                    applyFilters({ pinned: !pinned })
                                }
                            >
                                <Pin className="size-3.5" />
                                {t('queries.pinned')}
                                <span className="text-[11px]">
                                    {summary.pinned}
                                </span>
                            </Button>
                            {activeTag !== '' && tags.length === 0 && (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => applyFilters({ tag: '' })}
                                    title={t('queries.clearTagFilter')}
                                >
                                    <Tags className="size-3.5" />
                                    {activeTag}
                                    <X className="size-3.5" />
                                </Button>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setSaveViewOpen(true)}
                            >
                                <BookmarkPlus className="size-4" />
                                {t('queries.saveView')}
                            </Button>
                            <Button asChild size="sm">
                                <Link href={queries.create()}>
                                    <Plus className="size-4" />
                                    {t('queries.create')}
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-b bg-muted/20 px-5 py-2.5 text-xs text-muted-foreground">
                        <span>
                            {t('queries.totalExecutions')}:{' '}
                            <strong className="text-foreground">
                                {formatNumber(usage.total_executions)}
                            </strong>
                        </span>
                        <span>
                            {t('queries.globalSuccessRate')}:{' '}
                            <strong className="text-foreground">
                                {usage.success_rate === null
                                    ? '—'
                                    : `${formatNumber(usage.success_rate)} %`}
                            </strong>
                        </span>
                        <span>
                            {t('queries.lastExecution')}:{' '}
                            <strong className="text-foreground">
                                {usage.last_executed_at
                                    ? formatDate(usage.last_executed_at, {
                                          dateStyle: 'medium',
                                          timeStyle: 'short',
                                      })
                                    : t('queries.neverExecuted')}
                            </strong>
                        </span>
                    </div>

                    {savedViews.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2 border-b px-5 py-3">
                            <span className="mr-1 text-xs font-medium text-muted-foreground">
                                {t('queries.savedViews')}
                            </span>
                            {savedViews.map((view) => (
                                <div
                                    key={view.id}
                                    className="inline-flex overflow-hidden rounded-md border"
                                >
                                    <button
                                        type="button"
                                        onClick={() => applySavedView(view)}
                                        className="flex items-center gap-1.5 px-2.5 py-1.5 text-xs hover:bg-accent"
                                    >
                                        {view.name}
                                        {view.is_default && (
                                            <Badge
                                                variant="secondary"
                                                className="px-1 py-0 text-[9px]"
                                            >
                                                {t('common.default')}
                                            </Badge>
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => deleteSavedView(view)}
                                        className="border-l px-2 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                        aria-label={t('queries.deleteView', {
                                            name: view.name,
                                        })}
                                    >
                                        <X className="size-3" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}

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
                            queryPage.total === 0 &&
                            !hasActiveLibraryFilters ? (
                                <div className="space-y-3">
                                    <p>{t('queries.emptyInitial')}</p>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={queries.create()}>
                                            {t('queries.first')}
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                t('queries.emptySearch')
                            )
                        }
                    />

                    {/* Footer */}
                    <div className="flex items-center justify-between gap-3 border-t px-5 py-3 text-xs text-muted-foreground">
                        <span>
                            {queryPage.total === 0 ? (
                                `0 ${t('queries.result')}`
                            ) : (
                                <>
                                    {queryPage.from}–{queryPage.to}{' '}
                                    {t('queries.of')} {queryPage.total}
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
                                        const url = new URL(
                                            queryPage.prev_page_url,
                                            window.location.origin,
                                        );
                                        url.searchParams.set('view', 'none');
                                        router.visit(url.toString(), {
                                            only: ['queries'],
                                            preserveState: true,
                                            preserveScroll: true,
                                        });
                                    }
                                }}
                            >
                                {t('queries.previous')}
                            </Button>
                            <span>
                                {t('queries.page')} {queryPage.current_page}{' '}
                                {t('queries.of')} {queryPage.last_page}
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={queryPage.next_page_url === null}
                                onClick={() => {
                                    if (queryPage.next_page_url !== null) {
                                        const url = new URL(
                                            queryPage.next_page_url,
                                            window.location.origin,
                                        );
                                        url.searchParams.set('view', 'none');
                                        router.visit(url.toString(), {
                                            only: ['queries'],
                                            preserveState: true,
                                            preserveScroll: true,
                                        });
                                    }
                                }}
                            >
                                {t('queries.next')}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <SaveViewDialog
                open={saveViewOpen}
                onOpenChange={setSaveViewOpen}
                filters={currentFilters}
            />
        </>
    );
}

QueriesIndex.layout = {
    breadcrumbs: [{ title: 'Requêtes', href: queries.index() }],
};
