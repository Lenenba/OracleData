import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, Pencil, Plus, Table2, Trash2, TrendingUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/i18n/i18n-context';
import type { TranslationKey } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import dashboards from '@/routes/dashboards';
import queries from '@/routes/queries';

// ─── Types ───────────────────────────────────────────────────────────────────

type WidgetType = 'kpi' | 'table' | 'chart';

type QueryItem = {
    id: number;
    name: string;
    resource_path: string | null;
};

type Widget = {
    id: number;
    widget_type: WidgetType;
    title: string | null;
    position: number;
    widget_options: {
        column?: string;
        limit?: number;
        chart_type?: 'bar' | 'line';
    };
    query: QueryItem;
};

type Dashboard = {
    id: number;
    name: string;
    description: string | null;
    widgets: Widget[];
};

type WidgetResult =
    | { state: 'idle' }
    | { state: 'loading' }
    | { state: 'error'; message: string }
    | { state: 'done'; items: Record<string, unknown>[]; count: number };

// ─── Widget renderers ─────────────────────────────────────────────────────────

function KpiWidget({
    result,
    column,
    t,
}: {
    result: WidgetResult;
    column: string | undefined;
    t: (k: TranslationKey, p?: Record<string, string | number>) => string;
}) {
    if (result.state === 'loading') {
        return <Skeleton className="h-12 w-32" />;
    }

    if (result.state === 'error') {
        return (
            <p className="text-xs text-destructive">{t('dashboards.widgetError')}</p>
        );
    }

    if (result.state === 'done') {
        const row = result.items[0];
        const rawValue =
            row !== undefined && column !== undefined ? row[column] : result.count;
        const value =
            typeof rawValue === 'number'
                ? rawValue.toLocaleString()
                : rawValue !== undefined
                  ? String(rawValue)
                  : String(result.count);

        return (
            <div className="flex flex-col">
                <span className="text-3xl font-bold tabular-nums">{value}</span>
                {column && (
                    <span className="mt-0.5 text-xs text-muted-foreground">
                        {column}
                    </span>
                )}
            </div>
        );
    }

    return null;
}

function TableWidget({
    result,
    t,
}: {
    result: WidgetResult;
    t: (k: TranslationKey, p?: Record<string, string | number>) => string;
}) {
    if (result.state === 'loading') {
        return (
            <div className="space-y-1">
                <Skeleton className="h-6 w-full" />
                <Skeleton className="h-6 w-full" />
                <Skeleton className="h-6 w-4/5" />
            </div>
        );
    }

    if (result.state === 'error') {
        return (
            <p className="text-xs text-destructive">{t('dashboards.widgetError')}</p>
        );
    }

    if (result.state === 'done' && result.items.length > 0) {
        const columns = Object.keys(result.items[0] ?? {}).slice(0, 6);

        return (
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead>
                        <tr className="border-b bg-muted/30">
                            {columns.map((col) => (
                                <th
                                    key={col}
                                    className="px-2 py-1 text-left font-medium text-muted-foreground"
                                >
                                    {col}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {result.items.slice(0, 10).map((row, i) => (
                            <tr key={i} className="border-b last:border-0">
                                {columns.map((col) => (
                                    <td key={col} className="px-2 py-1">
                                        {row[col] !== null && row[col] !== undefined
                                            ? String(row[col])
                                            : '—'}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }

    return (
        <p className="text-xs text-muted-foreground">{t('dashboards.chartNoData')}</p>
    );
}

/**
 * Simple SVG bar chart (vertical bars, up to 20 data points).
 */
function ChartWidget({
    result,
    column,
    t,
}: {
    result: WidgetResult;
    column: string | undefined;
    t: (k: TranslationKey, p?: Record<string, string | number>) => string;
}) {
    if (result.state === 'loading') {
        return <Skeleton className="h-28 w-full" />;
    }

    if (result.state === 'error') {
        return (
            <p className="text-xs text-destructive">{t('dashboards.widgetError')}</p>
        );
    }

    if (result.state === 'done') {
        const items = result.items.slice(0, 20);

        if (items.length === 0) {
            return (
                <p className="text-xs text-muted-foreground">
                    {t('dashboards.chartNoData')}
                </p>
            );
        }

        const values = items.map((row) => {
            const raw = column !== undefined ? row[column] : null;

            return typeof raw === 'number' ? raw : 0;
        });
        const maxVal = Math.max(...values, 1);
        const W = 320;
        const H = 80;
        const barW = Math.floor((W - 4) / items.length) - 2;

        return (
            <svg
                viewBox={`0 0 ${W} ${H + 16}`}
                className="w-full"
                aria-label={column ?? 'chart'}
                role="img"
            >
                {values.map((val, i) => {
                    const barH = Math.max(2, Math.floor((val / maxVal) * H));
                    const x = 2 + i * (barW + 2);
                    const y = H - barH;

                    return (
                        <rect
                            key={i}
                            x={x}
                            y={y}
                            width={barW}
                            height={barH}
                            rx={2}
                            className="fill-primary/70"
                        />
                    );
                })}
                <text
                    x={2}
                    y={H + 14}
                    fontSize={8}
                    fill="currentColor"
                    className="text-muted-foreground"
                >
                    {maxVal.toLocaleString()}
                </text>
            </svg>
        );
    }

    return null;
}

// ─── Add-widget dialog ────────────────────────────────────────────────────────

function AddWidgetDialog({
    dashboardId,
    userQueries,
    open,
    onClose,
    t,
}: {
    dashboardId: number;
    userQueries: QueryItem[];
    open: boolean;
    onClose: () => void;
    t: (k: TranslationKey) => string;
}) {
    const [queryId, setQueryId] = useState('');
    const [widgetType, setWidgetType] = useState<WidgetType>('table');
    const [title, setTitle] = useState('');
    const [column, setColumn] = useState('');
    const [saving, setSaving] = useState(false);

    function reset() {
        setQueryId('');
        setWidgetType('table');
        setTitle('');
        setColumn('');
        setSaving(false);
    }

    function handleClose() {
        reset();
        onClose();
    }

    function submit() {
        if (queryId === '' || saving) return;

        setSaving(true);

        router.post(
            dashboards.widgets.store.url(dashboardId),
            {
                query_id: parseInt(queryId, 10),
                widget_type: widgetType,
                title: title || null,
                widget_options: column ? { column } : {},
            },
            {
                onSuccess: handleClose,
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={(o) => { if (!o) handleClose(); }}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('dashboards.addWidgetTitle')}</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="grid gap-2">
                        <Label>{t('dashboards.widgetQuery')}</Label>
                        <Select value={queryId} onValueChange={setQueryId}>
                            <SelectTrigger>
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                {userQueries.map((q) => (
                                    <SelectItem key={q.id} value={String(q.id)}>
                                        {q.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-2">
                        <Label>{t('dashboards.widgetType')}</Label>
                        <Select
                            value={widgetType}
                            onValueChange={(v) => setWidgetType(v as WidgetType)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="kpi">{t('dashboards.widgetTypeKpi')}</SelectItem>
                                <SelectItem value="table">{t('dashboards.widgetTypeTable')}</SelectItem>
                                <SelectItem value="chart">{t('dashboards.widgetTypeChart')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="wtitle">{t('dashboards.widgetTitle')}</Label>
                        <Input
                            id="wtitle"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                        />
                    </div>

                    {(widgetType === 'kpi' || widgetType === 'chart') && (
                        <div className="grid gap-2">
                            <Label htmlFor="wcol">{t('dashboards.widgetColumn')}</Label>
                            <Input
                                id="wcol"
                                value={column}
                                onChange={(e) => setColumn(e.target.value)}
                                placeholder={t('dashboards.widgetColumnPlaceholder')}
                            />
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={handleClose}>
                        {t('dashboards.cancel')}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={queryId === '' || saving}
                    >
                        {t('dashboards.widgetAdd')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ─── Widget card ──────────────────────────────────────────────────────────────

function WidgetCard({
    widget,
    dashboardId,
    t,
    formatNumber,
}: {
    widget: Widget;
    dashboardId: number;
    t: (k: TranslationKey, p?: Record<string, string | number>) => string;
    formatNumber: (n: number) => string;
}) {
    const [result, setResult] = useState<WidgetResult>({ state: 'idle' });

    useEffect(() => {
        setResult({ state: 'loading' });

        fetch(queries.run.url(widget.query.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': readCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                limit: widget.widget_options.limit ?? (widget.widget_type === 'kpi' ? 1 : 20),
            }),
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((data: { items?: unknown[]; count?: number }) => {
                setResult({
                    state: 'done',
                    items: Array.isArray(data.items)
                        ? (data.items as Record<string, unknown>[])
                        : [],
                    count: typeof data.count === 'number' ? data.count : 0,
                });
            })
            .catch(() => {
                setResult({ state: 'error', message: t('dashboards.widgetError') });
            });
    }, [widget.id]);

    const widgetIcon =
        widget.widget_type === 'kpi' ? (
            <TrendingUp className="size-3.5 text-muted-foreground" />
        ) : widget.widget_type === 'chart' ? (
            <BarChart3 className="size-3.5 text-muted-foreground" />
        ) : (
            <Table2 className="size-3.5 text-muted-foreground" />
        );

    return (
        <div className="flex flex-col rounded-xl border bg-card p-4 shadow-sm">
            <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-1.5 text-sm font-medium">
                    {widgetIcon}
                    {widget.title ?? widget.query.name}
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-6 text-muted-foreground/60 hover:text-destructive"
                    onClick={() =>
                        router.delete(
                            dashboards.widgets.destroy.url({
                                dashboard: dashboardId,
                                widget: widget.id,
                            }),
                            { preserveScroll: true },
                        )
                    }
                >
                    <Trash2 className="size-3" />
                </Button>
            </div>

            <div className="flex-1">
                {widget.widget_type === 'kpi' && (
                    <KpiWidget
                        result={result}
                        column={widget.widget_options.column}
                        t={t}
                    />
                )}
                {widget.widget_type === 'table' && (
                    <TableWidget result={result} t={t} />
                )}
                {widget.widget_type === 'chart' && (
                    <ChartWidget
                        result={result}
                        column={widget.widget_options.column}
                        t={t}
                    />
                )}
            </div>

            {result.state === 'done' && (
                <p className="mt-2 text-right text-xs text-muted-foreground">
                    {formatNumber(result.count)} {t('dashboards.chartRows')}
                </p>
            )}
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function DashboardsShow({
    dashboard,
    userQueries,
}: {
    dashboard: Dashboard;
    userQueries: QueryItem[];
}) {
    const { t, formatNumber } = useI18n();
    const [addOpen, setAddOpen] = useState(false);

    return (
        <>
            <Head title={dashboard.name} />

            <div className="px-6 py-6">
                <Heading
                    title={dashboard.name}
                    description={dashboard.description ?? undefined}
                    actions={
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={dashboards.edit(dashboard.id)}>
                                    <Pencil className="size-3.5" />
                                    {t('dashboards.edit')}
                                </Link>
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setAddOpen(true)}
                            >
                                <Plus className="size-4" />
                                {t('dashboards.addWidget')}
                            </Button>
                        </div>
                    }
                />

                {dashboard.widgets.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center">
                        <BarChart3 className="size-10 text-muted-foreground/40" />
                        <p className="text-sm font-medium">
                            {t('dashboards.widgetEmpty')}
                        </p>
                        <Button
                            type="button"
                            size="sm"
                            className="mt-2"
                            onClick={() => setAddOpen(true)}
                        >
                            <Plus className="size-4" />
                            {t('dashboards.addWidget')}
                        </Button>
                    </div>
                ) : (
                    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {dashboard.widgets.map((widget) => (
                            <WidgetCard
                                key={widget.id}
                                widget={widget}
                                dashboardId={dashboard.id}
                                t={t}
                                formatNumber={formatNumber}
                            />
                        ))}
                    </div>
                )}
            </div>

            <AddWidgetDialog
                dashboardId={dashboard.id}
                userQueries={userQueries}
                open={addOpen}
                onClose={() => setAddOpen(false)}
                t={t}
            />
        </>
    );
}

DashboardsShow.layout = {
    breadcrumbs: [
        { title: 'Dashboards', href: '/dashboards' },
        { title: 'Vue', href: '#' },
    ],
};
