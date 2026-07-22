import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    Braces,
    CheckCircle2,
    Clock3,
    Database,
    Eye,
    FileText,
    Gauge,
    PlayCircle,
    Plus,
    Server,
    Share2,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { DataTable, TableAvatar } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import { EntityChip } from '@/components/entity-chip';
import Heading from '@/components/heading';
import { QueryAccessLevelBadge } from '@/components/queries/query-access-level-badge';
import { QueryRecommendations } from '@/components/queries/query-recommendations';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n/i18n-context';
import { dashboard } from '@/routes';
import oracleTenants from '@/routes/oracle-tenants';
import queries from '@/routes/queries';
import type { OracleTenant, QueryAccessLevel } from '@/types';

type Stats = {
    totalQueries: number;
    myQueries: number;
    sharedQueries: number;
    activeTenants: number;
    executionsThisMonth: number;
    successRate: number;
    averageDurationMs: number;
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
    access_level: QueryAccessLevel;
    owner: string;
    can: {
        update: boolean;
        execute: boolean;
        clone: boolean;
        manage_sharing: boolean;
    };
};

type DashboardProps = {
    stats: Stats;
    queriesPerWeek: number[];
    domainBreakdown: DomainSlice[];
    recentQueries: RecentQuery[];
    tenants: OracleTenant[];
};

const ACCENTS = [
    'bg-primary',
    'bg-purple',
    'bg-success',
    'bg-info',
    'bg-warning',
    'bg-destructive',
];

type ChartPoint = {
    x: number;
    y: number;
};

function buildSmoothPath(
    points: ChartPoint[],
    minY: number,
    maxY: number,
): string {
    if (points.length === 0) {
        return '';
    }

    const clampY = (value: number) => Math.min(Math.max(value, minY), maxY);
    let path = `M ${points[0].x.toFixed(2)} ${points[0].y.toFixed(2)}`;

    for (let index = 1; index < points.length; index += 1) {
        const point = points[index];
        const previous = points[index - 1];
        const beforePrevious = points[index - 2] ?? previous;
        const next = points[index + 1] ?? point;
        const controlOneX = previous.x + (point.x - beforePrevious.x) / 6;
        const controlOneY = clampY(
            previous.y + (point.y - beforePrevious.y) / 6,
        );
        const controlTwoX = point.x - (next.x - previous.x) / 6;
        const controlTwoY = clampY(point.y - (next.y - previous.y) / 6);

        path += ` C ${controlOneX.toFixed(2)} ${controlOneY.toFixed(2)}, ${controlTwoX.toFixed(2)} ${controlTwoY.toFixed(2)}, ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
    }

    return path;
}

function SparkArea({ values }: { values: number[] }) {
    const fallbackValue = values[0] ?? 0;
    const series = values.length > 1 ? values : [fallbackValue, fallbackValue];
    const width = 1000;
    const height = 160;
    const horizontalPadding = 8;
    const top = 12;
    const baseline = 145;
    const max = Math.max(...series, 1);
    const points: ChartPoint[] = series.map((value, index) => {
        const x =
            horizontalPadding +
            (index / Math.max(series.length - 1, 1)) *
                (width - horizontalPadding * 2);
        const y = baseline - (value / max) * (baseline - top);

        return { x, y };
    });
    const linePath = buildSmoothPath(points, top, baseline);
    const firstPoint = points[0];
    const lastPoint = points[points.length - 1];
    const areaPath = `${linePath} L ${lastPoint.x.toFixed(2)} ${baseline} L ${firstPoint.x.toFixed(2)} ${baseline} Z`;

    return (
        <div className="relative h-[160px] w-full" aria-hidden="true">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="absolute inset-0 size-full overflow-visible"
                preserveAspectRatio="none"
                shapeRendering="geometricPrecision"
                aria-hidden="true"
            >
                <defs>
                    <linearGradient
                        id="paces-query-area"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stopColor="#236dc9"
                            stopOpacity=".28"
                        />
                        <stop
                            offset="100%"
                            stopColor="#236dc9"
                            stopOpacity=".015"
                        />
                    </linearGradient>
                </defs>
                {[32, 68, 104, 140].map((y) => (
                    <line
                        key={y}
                        x1="0"
                        y1={y}
                        x2={width}
                        y2={y}
                        stroke="currentColor"
                        strokeDasharray="5 9"
                        vectorEffect="non-scaling-stroke"
                        className="text-border"
                    />
                ))}
                <path d={areaPath} fill="url(#paces-query-area)" />
                <path
                    d={linePath}
                    fill="none"
                    stroke="#236dc9"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    vectorEffect="non-scaling-stroke"
                />
            </svg>

            {points.map((point, index) => (
                <span
                    key={index}
                    className="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-[2.5px] border-primary bg-white shadow-[0_0_0_2px_rgba(35,109,201,0.08)]"
                    style={{
                        left: `${(point.x / width) * 100}%`,
                        top: `${(point.y / height) * 100}%`,
                    }}
                />
            ))}
        </div>
    );
}

function MetricCard({
    title,
    value,
    caption,
    icon: Icon,
    tone,
    badge,
    children,
}: {
    title: string;
    value: string | number;
    caption: string;
    icon: LucideIcon;
    tone: string;
    badge?: string;
    children?: React.ReactNode;
}) {
    return (
        <article className="card h-full">
            <div className="card-body">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="mb-2.5 text-[13px] font-bold tracking-wide text-muted-foreground uppercase">
                            {title}
                        </p>
                        <div className="flex items-center gap-2.5">
                            <span
                                className={`grid size-9 place-items-center rounded-full text-white ${tone}`}
                            >
                                <Icon className="size-4.5" />
                            </span>
                            <strong className="text-xl font-semibold text-foreground tabular-nums">
                                {value}
                            </strong>
                            {badge && (
                                <span className="badge ml-auto bg-success/15 text-success">
                                    {badge}
                                </span>
                            )}
                        </div>
                    </div>
                    <select
                        aria-label="Période"
                        defaultValue="month"
                        className="form-select form-select-sm w-[104px]"
                    >
                        <option value="month">Ce mois</option>
                        <option value="week">7 jours</option>
                        <option value="all">Tout</option>
                    </select>
                </div>
                {children}
                <p className="mt-3 text-[12px] text-muted-foreground">
                    {caption}
                </p>
            </div>
        </article>
    );
}

function DomainBreakdownPanel({ slices }: { slices: DomainSlice[] }) {
    const total = slices.reduce((sum, slice) => sum + slice.count, 0);

    return (
        <section className="card h-full">
            <div className="card-header">
                <div>
                    <h3 className="card-title">Répartition par domaine</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Domaines Oracle couverts par la bibliothèque
                    </p>
                </div>
                <Badge variant="secondary">{total} requêtes</Badge>
            </div>
            <div className="card-body">
                {total === 0 ? (
                    <div className="rounded border border-dashed p-10 text-center text-sm text-muted-foreground">
                        Les domaines apparaîtront dès votre première requête.
                    </div>
                ) : (
                    <div className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                        {slices.map((slice, index) => {
                            const percentage = Math.round(
                                (slice.count / total) * 100,
                            );

                            return (
                                <div key={slice.domain}>
                                    <div className="mb-1.5 flex items-center justify-between text-[13px]">
                                        <span className="flex min-w-0 items-center gap-2 font-semibold">
                                            <span
                                                className={`size-2 shrink-0 rounded-full ${ACCENTS[index % ACCENTS.length]}`}
                                            />
                                            <span className="truncate">
                                                {slice.domain}
                                            </span>
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {slice.count} · {percentage}%
                                        </span>
                                    </div>
                                    <div className="h-2.5 overflow-hidden rounded-sm bg-muted">
                                        <div
                                            className={`h-full ${ACCENTS[index % ACCENTS.length]}`}
                                            style={{ width: `${percentage}%` }}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </section>
    );
}

function TenantsPanel({ tenants }: { tenants: OracleTenant[] }) {
    const { t } = useI18n();

    return (
        <section className="card h-full">
            <div className="card-header justify-between">
                <div>
                    <h3 className="card-title">{t('connections.title')}</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Environnements Oracle configurés
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href={oracleTenants.index()}>
                        {t('connections.manage')}
                    </Link>
                </Button>
            </div>
            <div className="card-body p-0">
                {tenants.length === 0 ? (
                    <div className="m-5 rounded border border-dashed p-8 text-center">
                        <Database className="mx-auto mb-2 size-8 text-muted-foreground/50" />
                        <p className="font-semibold">
                            {t('connections.empty')}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t('connections.emptyDescription')}
                        </p>
                        <Button asChild size="sm" className="mt-4">
                            <Link href={oracleTenants.index()}>
                                <Plus /> {t('connections.add')}
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <ul className="divide-y divide-border">
                        {tenants.slice(0, 5).map((tenant) => (
                            <li
                                key={tenant.key}
                                className="flex items-center gap-3 px-5 py-3.5"
                            >
                                <span
                                    className={`grid size-9 shrink-0 place-items-center rounded-full ${tenant.is_active ? 'bg-success/15 text-success' : 'bg-destructive/15 text-destructive'}`}
                                >
                                    <Server className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13px] font-semibold">
                                        {tenant.label}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {tenant.base_url ||
                                            'URL non renseignée'}
                                    </p>
                                </div>
                                {tenant.is_default ? (
                                    <Badge variant="secondary">Défaut</Badge>
                                ) : (
                                    <span
                                        className={`size-2 rounded-full ${tenant.is_active ? 'bg-success' : 'bg-destructive'}`}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

function RecentQueriesTable({ rows }: { rows: RecentQuery[] }) {
    const { t } = useI18n();
    const columns: DataTableColumn<RecentQuery>[] = [
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
                            onClick={(event) => event.stopPropagation()}
                            className="block max-w-[260px] truncate font-semibold text-foreground hover:text-primary"
                        >
                            {query.name}
                        </Link>
                        <span className="block max-w-[260px] truncate text-xs text-muted-foreground">
                            {query.description || 'Aucune description'}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            key: 'tenant',
            header: t('connections.environment'),
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
            cell: (query) => (
                <Badge
                    variant={query.mode === 'agent' ? 'default' : 'secondary'}
                >
                    {query.mode === 'agent' ? 'Analyse' : 'Requête'}
                </Badge>
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
            header: 'Propriétaire',
            icon: User,
            cellClassName: 'text-muted-foreground',
            cell: (query) => query.owner,
        },
    ];

    return (
        <section className="card">
            <div className="card-header justify-between">
                <div>
                    <h3 className="card-title">Requêtes récentes</h3>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Les cinq dernières requêtes accessibles
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href={queries.index()}>Voir la bibliothèque</Link>
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
        </section>
    );
}

export default function Dashboard({
    stats,
    queriesPerWeek,
    domainBreakdown,
    recentQueries,
    tenants,
}: DashboardProps) {
    const { t, formatNumber } = useI18n();
    const currentWeek = queriesPerWeek.at(-1) ?? 0;
    const previousWeek = queriesPerWeek.at(-2) ?? 0;
    const trend =
        previousWeek > 0
            ? Math.round(((currentWeek - previousWeek) / previousWeek) * 100)
            : currentWeek > 0
              ? 100
              : 0;

    const overview = [
        {
            label: 'Mes requêtes',
            value: stats.myQueries,
            icon: FileText,
            tone: 'bg-info/15 text-info',
        },
        {
            label: 'Partagées',
            value: stats.sharedQueries,
            icon: Share2,
            tone: 'bg-purple/15 text-purple',
        },
        {
            label: 'Exécutions',
            value: stats.executionsThisMonth,
            icon: PlayCircle,
            tone: 'bg-success/15 text-success',
        },
        {
            label: 'Temps moyen',
            value: `${formatNumber(stats.averageDurationMs)} ms`,
            icon: Clock3,
            tone: 'bg-warning/15 text-warning',
        },
    ];

    return (
        <>
            <Head title="Tableau de bord" />

            <div className="p-5">
                <Heading
                    title="Analytics"
                    description="Vue d'ensemble de vos requêtes, exécutions et environnements Oracle."
                    actions={
                        <Button asChild>
                            <Link href={queries.create()}>
                                <Plus /> Nouvelle requête
                            </Link>
                        </Button>
                    }
                />

                <div className="mb-5 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <MetricCard
                        title="Requêtes accessibles"
                        value={formatNumber(stats.totalQueries)}
                        caption={`${currentWeek} ajoutées cette semaine`}
                        icon={Database}
                        tone="bg-success"
                        badge={`${trend >= 0 ? '+' : ''}${trend}%`}
                    >
                        <div
                            className="mt-5 flex h-12 items-end gap-1.5"
                            aria-hidden="true"
                        >
                            {(queriesPerWeek.length
                                ? queriesPerWeek
                                : [0, 0, 0, 0, 0, 0, 0, 0]
                            ).map((value, index, all) => {
                                const max = Math.max(...all, 1);

                                return (
                                    <span
                                        key={index}
                                        className="min-h-1 flex-1 rounded-t-sm bg-primary/75"
                                        style={{
                                            height: `${Math.max((value / max) * 100, 8)}%`,
                                        }}
                                    />
                                );
                            })}
                        </div>
                    </MetricCard>

                    <MetricCard
                        title={t('dashboard.executionsThisMonth')}
                        value={formatNumber(stats.executionsThisMonth)}
                        caption={t('dashboard.executionsCaption')}
                        icon={Activity}
                        tone="bg-purple"
                    >
                        <div className="mt-6">
                            <div className="mb-1.5 flex justify-between text-xs font-semibold">
                                <span>Taux de réussite</span>
                                <span>{formatNumber(stats.successRate)}%</span>
                            </div>
                            <div className="h-3 overflow-hidden rounded-sm bg-muted">
                                <div
                                    className="h-full bg-purple"
                                    style={{
                                        width: `${Math.min(stats.successRate, 100)}%`,
                                    }}
                                />
                            </div>
                        </div>
                    </MetricCard>

                    <MetricCard
                        title={t('connections.title')}
                        value={formatNumber(stats.activeTenants)}
                        caption={t('connections.activeCaption')}
                        icon={Server}
                        tone="bg-info"
                    >
                        <div className="mt-5 grid grid-cols-2 gap-2.5">
                            <div className="rounded bg-info/10 px-3 py-2.5">
                                <p className="text-[11px] font-bold text-info uppercase">
                                    Actives
                                </p>
                                <p className="mt-1 text-lg font-semibold">
                                    {stats.activeTenants}
                                </p>
                            </div>
                            <div className="rounded bg-muted px-3 py-2.5">
                                <p className="text-[11px] font-bold text-muted-foreground uppercase">
                                    Total
                                </p>
                                <p className="mt-1 text-lg font-semibold">
                                    {tenants.length}
                                </p>
                            </div>
                        </div>
                    </MetricCard>
                </div>

                <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-4">
                    <section className="card xl:col-span-3">
                        <div className="card-header justify-between">
                            <h3 className="card-title">
                                Activité des requêtes
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    (8 dernières semaines)
                                </span>
                            </h3>
                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm">
                                    Exporter
                                </Button>
                                <Button asChild variant="secondary" size="sm">
                                    <Link href={queries.index()}>
                                        Bibliothèque
                                    </Link>
                                </Button>
                            </div>
                        </div>
                        <div className="grid border-b border-border sm:grid-cols-2 lg:grid-cols-4">
                            {overview.map(
                                ({ label, value, icon: Icon, tone }) => (
                                    <div
                                        key={label}
                                        className="flex items-center justify-center gap-3 border-b border-border px-4 py-4 last:border-b-0 lg:border-r lg:border-b-0 lg:last:border-r-0 sm:[&:nth-child(odd)]:border-r"
                                    >
                                        <span
                                            className={`grid size-9 shrink-0 place-items-center rounded-full ${tone}`}
                                        >
                                            <Icon className="size-4" />
                                        </span>
                                        <span>
                                            <span className="block text-xs font-semibold text-muted-foreground">
                                                {label}
                                            </span>
                                            <strong className="text-lg font-semibold tabular-nums">
                                                {typeof value === 'number'
                                                    ? formatNumber(value)
                                                    : value}
                                            </strong>
                                        </span>
                                    </div>
                                ),
                            )}
                        </div>
                        <div className="card-body pb-2">
                            <SparkArea values={queriesPerWeek} />
                            <div className="flex justify-between px-1 text-[11px] font-semibold text-muted-foreground uppercase">
                                {queriesPerWeek.map((_, index) => (
                                    <span key={index}>S{index + 1}</span>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="card">
                        <div className="card-header">
                            <h3 className="card-title">Santé de l'espace</h3>
                        </div>
                        <div className="card-body">
                            <div
                                className="mx-auto grid size-36 place-items-center rounded-full bg-[conic-gradient(#02bc9c_var(--success-rate),#eef2f7_0)] p-3"
                                style={
                                    {
                                        '--success-rate': `${Math.min(stats.successRate, 100)}%`,
                                    } as React.CSSProperties
                                }
                            >
                                <div className="grid size-full place-items-center rounded-full bg-card text-center">
                                    <span>
                                        <strong className="block text-2xl font-semibold text-foreground">
                                            {formatNumber(stats.successRate)}%
                                        </strong>
                                        <small className="text-[11px] text-muted-foreground">
                                            Réussite
                                        </small>
                                    </span>
                                </div>
                            </div>
                            <div className="mt-5 space-y-3 border-t border-dashed border-border pt-4">
                                <div className="flex items-center justify-between text-[13px]">
                                    <span className="flex items-center gap-2">
                                        <CheckCircle2 className="size-4 text-success" />{' '}
                                        Exécutions réussies
                                    </span>
                                    <strong>
                                        {formatNumber(stats.successRate)}%
                                    </strong>
                                </div>
                                <div className="flex items-center justify-between text-[13px]">
                                    <span className="flex items-center gap-2">
                                        <Clock3 className="size-4 text-warning" />{' '}
                                        Durée moyenne
                                    </span>
                                    <strong>
                                        {formatNumber(stats.averageDurationMs)}{' '}
                                        ms
                                    </strong>
                                </div>
                                <div className="flex items-center justify-between text-[13px]">
                                    <span className="flex items-center gap-2">
                                        <Gauge className="size-4 text-primary" />{' '}
                                        Cette semaine
                                    </span>
                                    <strong>{currentWeek}</strong>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-12">
                    <div className="xl:col-span-7">
                        <DomainBreakdownPanel slices={domainBreakdown} />
                    </div>
                    <div className="xl:col-span-5">
                        <TenantsPanel tenants={tenants} />
                    </div>
                </div>

                <div className="mb-5">
                    <QueryRecommendations />
                </div>
                <RecentQueriesTable rows={recentQueries} />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Analytics', href: dashboard() }],
};
