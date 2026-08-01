import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    RefreshCw,
    XCircle,
    Zap,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n/i18n-context';
import oracleTenants from '@/routes/oracle-tenants';
import type { OicIntegration, OicTenantShape } from '@/types';

const oicMonitor = {
    index: (id: number) => `/oracle-tenants/${id}/oic-monitor`,
    errors: (id: number) => `/oracle-tenants/${id}/oic-monitor/errors`,
    show: (id: number, integrationId: string) =>
        `/oracle-tenants/${id}/oic-monitor/${encodeURIComponent(integrationId)}`,
};

type Props = {
    tenant: OicTenantShape;
    integrations: OicIntegration[];
    error: string | null;
};

function StatusBadge({ status }: { status: string }) {
    if (status === 'ACTIVATED') {
        return (
            <Badge className="bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                <CheckCircle2 className="mr-1 size-3" />
                Activée
            </Badge>
        );
    }

    if (status === 'CONFIGURED') {
        return <Badge variant="secondary">Configurée</Badge>;
    }

    return <Badge variant="outline">{status}</Badge>;
}

function SuccessRate({ integration }: { integration: OicIntegration }) {
    const total =
        integration.completed_count +
        integration.failed_count +
        integration.aborted_count;

    if (total === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    const rate = Math.round((integration.completed_count / total) * 100);
    const color =
        rate >= 95
            ? 'text-emerald-600 dark:text-emerald-400'
            : rate >= 80
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-red-600 dark:text-red-400';

    return (
        <span className={`font-semibold tabular-nums ${color}`}>{rate} %</span>
    );
}

export default function OicMonitorIndex({
    tenant,
    integrations,
    error,
}: Props) {
    const { t } = useI18n();

    const totalCompleted = integrations.reduce(
        (s, i) => s + i.completed_count,
        0,
    );
    const totalFailed = integrations.reduce((s, i) => s + i.failed_count, 0);
    const totalAborted = integrations.reduce((s, i) => s + i.aborted_count, 0);
    const totalProcessing = integrations.reduce(
        (s, i) => s + i.processing_count,
        0,
    );

    return (
        <>
            <Head title={t('oic.pageTitle')} />

            <div className="page-header">
                <Heading
                    title={t('oic.pageTitle')}
                    description={`${tenant.label} · ${tenant.key}`}
                />
                <div className="flex items-center gap-2">
                    <Link href={oicMonitor.errors(tenant.id)}>
                        <Button variant="outline" size="sm">
                            <XCircle className="mr-1.5 size-4" />
                            {t('oic.viewErrors')}
                        </Button>
                    </Link>
                    <Link href={oicMonitor.index(tenant.id)}>
                        <Button variant="outline" size="sm">
                            <RefreshCw className="mr-1.5 size-4" />
                            {t('common.refresh')}
                        </Button>
                    </Link>
                </div>
            </div>

            {error && (
                <div className="alert alert-error mb-6">
                    <AlertTriangle className="size-4 shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            {/* KPI summary */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    {
                        label: t('oic.completed'),
                        value: totalCompleted,
                        color: 'text-emerald-600 dark:text-emerald-400',
                    },
                    {
                        label: t('oic.failed'),
                        value: totalFailed,
                        color: 'text-red-600 dark:text-red-400',
                    },
                    {
                        label: t('oic.aborted'),
                        value: totalAborted,
                        color: 'text-amber-600 dark:text-amber-400',
                    },
                    {
                        label: t('oic.processing'),
                        value: totalProcessing,
                        color: 'text-blue-600 dark:text-blue-400',
                    },
                ].map(({ label, value, color }) => (
                    <div key={label} className="card">
                        <div className="card-body">
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {label}
                            </p>
                            <p
                                className={`mt-1 text-2xl font-bold tabular-nums ${color}`}
                            >
                                {value.toLocaleString()}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            {integrations.length === 0 && !error ? (
                <div className="empty-state">
                    <Zap className="size-8 text-muted-foreground" />
                    <p className="mt-2 text-muted-foreground">
                        {t('oic.noIntegrations')}
                    </p>
                </div>
            ) : (
                <div className="card overflow-hidden">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>{t('oic.integrationName')}</th>
                                <th>{t('oic.status')}</th>
                                <th className="text-right">
                                    {t('oic.successRate')}
                                </th>
                                <th className="text-right">
                                    {t('oic.completed')}
                                </th>
                                <th className="text-right">
                                    {t('oic.failed')}
                                </th>
                                <th className="text-right">
                                    {t('oic.processing')}
                                </th>
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {integrations.map((integration) => (
                                <tr key={integration.id}>
                                    <td>
                                        <div className="font-medium">
                                            {integration.name}
                                        </div>
                                        {integration.code && (
                                            <code className="text-xs text-muted-foreground">
                                                {integration.code}
                                            </code>
                                        )}
                                    </td>
                                    <td>
                                        <StatusBadge
                                            status={integration.status}
                                        />
                                    </td>
                                    <td className="text-right">
                                        <SuccessRate
                                            integration={integration}
                                        />
                                    </td>
                                    <td className="text-right text-emerald-600 tabular-nums dark:text-emerald-400">
                                        {integration.completed_count}
                                    </td>
                                    <td className="text-right text-red-600 tabular-nums dark:text-red-400">
                                        {integration.failed_count}
                                    </td>
                                    <td className="text-right text-blue-600 tabular-nums dark:text-blue-400">
                                        {integration.processing_count}
                                    </td>
                                    <td className="text-right">
                                        {integration.id && (
                                            <Link
                                                href={oicMonitor.show(
                                                    tenant.id,
                                                    integration.id,
                                                )}
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                >
                                                    <ChevronRight className="size-4" />
                                                </Button>
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    );
}

OicMonitorIndex.layout = {
    breadcrumbs: (page: { props: Props }) => [
        { title: 'Connexions Oracle', href: oracleTenants.index() },
        { title: page.props.tenant.label, href: oracleTenants.index() },
        {
            title: 'Monitoring OIC',
            href: oicMonitor.index(page.props.tenant.id),
        },
    ],
};
