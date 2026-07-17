import { Head, Link, router } from '@inertiajs/react';
import {
    Braces,
    Database,
    Eye,
    FileText,
    Plus,
    Server,
    User,
} from 'lucide-react';
import { DataTable, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import { EntityChip } from '@/components/entity-chip';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import oracleTenants from '@/routes/oracle-tenants';
import queries from '@/routes/queries';

type Stats = {
    totalQueries: number;
    myQueries: number;
    sharedQueries: number;
    activeTenants: number;
};

type DomainSlice = {
    domain: string;
    count: number;
};

type RecentQuery = {
    id: number;
    name: string;
    description: string | null;
    mode: 'single' | 'agent';
    tenant_label: string | null;
    visibility: 'private' | 'shared';
    owner: string;
    can: { update: boolean };
};

type TenantDetail = {
    key: string;
    label: string;
    base_url: string;
    username: string;
    source: 'config' | 'database';
    is_default: boolean;
    is_active: boolean;
};

type DashboardProps = {
    stats: Stats;
    queriesPerWeek: number[];
    domainBreakdown: DomainSlice[];
    recentQueries: RecentQuery[];
    tenants: TenantDetail[];
};

// Couleurs cycliques des barres de répartition (pastilles + remplissage)
const DOMAIN_BAR_COLORS = [
    'bg-slate-800 dark:bg-slate-200',
    'bg-indigo-500',
    'bg-violet-500',
    'bg-cyan-500',
    'bg-emerald-500',
    'bg-amber-500',
];

function DomainBreakdownPanel({ slices }: { slices: DomainSlice[] }) {
    const total = slices.reduce((sum, slice) => sum + slice.count, 0);

    return (
        <div className="rounded-xl border bg-card p-5">
            <h3 className="text-sm font-semibold">Répartition par domaine</h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
                Domaines Oracle couverts par vos requêtes enregistrées.
            </p>

            {total === 0 ? (
                <div className="mt-6 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Les domaines apparaîtront dès votre première requête.
                </div>
            ) : (
                <ul className="mt-5 space-y-4">
                    {slices.map((slice, index) => {
                        const color =
                            DOMAIN_BAR_COLORS[index % DOMAIN_BAR_COLORS.length];
                        const percent = Math.round((slice.count / total) * 100);

                        return (
                            <li key={slice.domain}>
                                <div className="flex items-center justify-between gap-2 text-sm">
                                    <span className="flex items-center gap-2">
                                        <span
                                            className={`size-2 rounded-full ${color}`}
                                        />
                                        {slice.domain}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {slice.count} ({percent}%)
                                    </span>
                                </div>
                                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className={`h-full rounded-full ${color}`}
                                        style={{ width: `${percent}%` }}
                                    />
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

function TenantsPanel({ tenants }: { tenants: TenantDetail[] }) {
    return (
        <div className="rounded-xl border bg-card p-5">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <h3 className="text-sm font-semibold">
                        Environnements Oracle
                    </h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Connexions Oracle Fusion disponibles.
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href={oracleTenants.index()}>Gérer</Link>
                </Button>
            </div>

            {tenants.length === 0 ? (
                <div className="mt-6 rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Aucun tenant configuré.
                </div>
            ) : (
                <ul className="mt-4 divide-y">
                    {tenants.map((tenant) => (
                        <li
                            key={tenant.key}
                            className="flex items-center justify-between gap-3 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="flex items-center gap-2 truncate text-sm font-medium">
                                    <span
                                        className={`inline-block size-2 shrink-0 rounded-full ${
                                            tenant.is_active
                                                ? 'bg-emerald-500'
                                                : 'bg-red-400'
                                        }`}
                                        title={
                                            tenant.is_active
                                                ? 'Actif'
                                                : 'Inactif'
                                        }
                                    />
                                    {tenant.label}
                                </p>
                                <p className="mt-0.5 truncate pl-4 text-xs text-muted-foreground">
                                    {tenant.base_url || '—'}
                                </p>
                            </div>
                            {tenant.is_default && (
                                <Badge variant="secondary" className="text-xs">
                                    Défaut
                                </Badge>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RecentQueriesTable({ rows }: { rows: RecentQuery[] }) {
    const columns: DataTableColumn<RecentQuery>[] = [
        {
            key: 'name',
            header: 'Nom',
            icon: FileText,
            cell: (query) => (
                <div className="flex items-center gap-3">
                    <TableAvatar label={query.name} />
                    <Link
                        href={queries.show(query.id)}
                        onClick={(e) => e.stopPropagation()}
                        className="font-medium text-foreground underline-offset-2 hover:underline"
                    >
                        {query.name}
                    </Link>
                </div>
            ),
        },
        {
            key: 'tenant',
            header: 'Tenant',
            icon: Database,
            cell: (query) =>
                query.tenant_label ? (
                    <EntityChip icon={Server} label={query.tenant_label} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'mode',
            header: 'Mode',
            icon: Braces,
            cellClassName: 'text-muted-foreground',
            cell: (query) => (query.mode === 'agent' ? 'Analyse' : 'Requête'),
        },
        {
            key: 'visibility',
            header: 'Visibilité',
            icon: Eye,
            cell: (query) => (
                <Badge
                    variant={
                        query.visibility === 'shared' ? 'default' : 'secondary'
                    }
                    className="text-xs"
                >
                    {query.visibility === 'shared' ? 'Partagée' : 'Privée'}
                </Badge>
            ),
        },
        {
            key: 'owner',
            header: 'Propriétaire',
            icon: User,
            cellClassName: 'text-muted-foreground',
            cell: (query) => query.owner,
        },
    ];

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <div className="flex items-center justify-between gap-2 border-b px-5 py-4">
                <div>
                    <h3 className="text-sm font-semibold">Requêtes récentes</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Les 5 dernières requêtes accessibles.
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href={queries.index()}>Voir tout</Link>
                </Button>
            </div>

            <DataTable
                columns={columns}
                rows={rows}
                rowKey={(query) => query.id}
                onRowClick={(query) => router.visit(queries.show(query.id))}
                empty={
                    <div className="space-y-3">
                        <p>Aucune requête pour l'instant.</p>
                        <Button asChild size="sm">
                            <Link href={queries.create()}>
                                Créer ma première requête
                            </Link>
                        </Button>
                    </div>
                }
            />
        </div>
    );
}

export default function Dashboard({
    stats,
    queriesPerWeek,
    domainBreakdown,
    recentQueries,
    tenants,
}: DashboardProps) {
    const thisWeek = queriesPerWeek.at(-1) ?? 0;
    const previousWeek = queriesPerWeek.at(-2) ?? 0;
    const weeklyTrend =
        thisWeek + previousWeek > 0
            ? {
                  direction:
                      thisWeek >= previousWeek
                          ? ('up' as const)
                          : ('down' as const),
                  label: `${thisWeek} cette semaine`,
              }
            : undefined;

    return (
        <>
            <Head title="Tableau de bord" />

            <div className="px-6 py-6">
                <Heading
                    title="Tableau de bord"
                    description="Vue d'ensemble de vos requêtes et environnements Oracle."
                    actions={
                        <Button asChild>
                            <Link href={queries.create()}>
                                <Plus className="size-4" />
                                Nouvelle requête
                            </Link>
                        </Button>
                    }
                />

                <div className="space-y-6">
                    {/* KPIs */}
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Requêtes accessibles"
                            value={stats.totalQueries}
                            trend={weeklyTrend}
                            caption="8 dernières semaines"
                            series={queriesPerWeek}
                        />
                        <StatCard
                            label="Mes requêtes"
                            value={stats.myQueries}
                            caption="Créées par vous"
                        />
                        <StatCard
                            label="Requêtes partagées"
                            value={stats.sharedQueries}
                            caption="Visibles par toute l'équipe"
                        />
                        <StatCard
                            label="Tenants Oracle"
                            value={stats.activeTenants}
                            caption="Environnements configurés"
                        />
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <DomainBreakdownPanel slices={domainBreakdown} />
                        <TenantsPanel tenants={tenants} />
                    </div>

                    <RecentQueriesTable rows={recentQueries} />
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Tableau de bord',
            href: dashboard(),
        },
    ],
};
