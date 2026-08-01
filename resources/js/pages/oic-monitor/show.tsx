import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    XCircle,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n/i18n-context';
import oracleTenants from '@/routes/oracle-tenants';
import type { OicInstance, OicTenantShape } from '@/types';

const oicMonitor = {
    index: (id: number) => `/oracle-tenants/${id}/oic-monitor`,
};

type Integration = {
    id?: string;
    name?: string;
    description?: string;
    status?: string;
};

type Props = {
    tenant: OicTenantShape;
    integration_id: string;
    integration: Integration | null;
    instances: OicInstance[];
    error: string | null;
};

function InstanceStatusBadge({ status }: { status: string }) {
    if (status === 'COMPLETED') {
        return (
            <Badge className="bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                <CheckCircle2 className="mr-1 size-3" />
                Complétée
            </Badge>
        );
    }

    if (status === 'FAILED') {
        return (
            <Badge className="bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-400">
                <XCircle className="mr-1 size-3" />
                Échouée
            </Badge>
        );
    }

    if (status === 'ABORTED') {
        return (
            <Badge className="bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400">
                Abandonnée
            </Badge>
        );
    }

    if (status === 'PROCESSING') {
        return (
            <Badge className="bg-blue-50 text-blue-700 dark:bg-blue-950/60 dark:text-blue-400">
                <Clock className="mr-1 size-3" />
                En cours
            </Badge>
        );
    }

    return <Badge variant="outline">{status}</Badge>;
}

function duration(start: string | null, end: string | null): string {
    if (!start || !end) {
        return '—';
    }

    const ms = new Date(end).getTime() - new Date(start).getTime();

    if (ms < 1000) {
        return `${ms} ms`;
    }

    if (ms < 60_000) {
        return `${(ms / 1000).toFixed(1)} s`;
    }

    return `${Math.floor(ms / 60_000)} min ${Math.round((ms % 60_000) / 1000)} s`;
}

export default function OicMonitorShow({
    tenant,
    integration_id,
    integration,
    instances,
    error,
}: Props) {
    const { t, formatDate } = useI18n();

    const completed = instances.filter((i) => i.status === 'COMPLETED').length;
    const failed = instances.filter((i) => i.status === 'FAILED').length;
    const successRate =
        instances.length > 0
            ? Math.round((completed / instances.length) * 100)
            : null;

    return (
        <>
            <Head title={integration?.name ?? t('oic.pageTitle')} />

            <div className="page-header">
                <div className="flex items-center gap-3">
                    <Link href={oicMonitor.index(tenant.id)}>
                        <Button variant="ghost" size="icon" className="size-8">
                            <ArrowLeft className="size-4" />
                        </Button>
                    </Link>
                    <Heading
                        title={integration?.name ?? integration_id}
                        description={integration?.description ?? undefined}
                    />
                </div>
            </div>

            {error && (
                <div className="alert alert-error mb-6">
                    <AlertTriangle className="size-4 shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            {/* Quick stats */}
            <div className="mb-6 flex flex-wrap gap-4 text-sm">
                <span>
                    <span className="font-semibold text-emerald-600 tabular-nums dark:text-emerald-400">
                        {completed}
                    </span>{' '}
                    {t('oic.completed')}
                </span>
                <span>
                    <span className="font-semibold text-red-600 tabular-nums dark:text-red-400">
                        {failed}
                    </span>{' '}
                    {t('oic.failed')}
                </span>
                {successRate !== null && (
                    <span className="text-muted-foreground">
                        {t('oic.successRate')} :{' '}
                        <strong>{successRate} %</strong>
                    </span>
                )}
            </div>

            {instances.length === 0 && !error ? (
                <p className="text-muted-foreground">{t('oic.noInstances')}</p>
            ) : (
                <div className="card overflow-hidden">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>{t('oic.instanceStatus')}</th>
                                <th>{t('oic.startedAt')}</th>
                                <th>{t('oic.duration')}</th>
                                <th>{t('oic.businessId')}</th>
                                <th>{t('oic.errorMessage')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {instances.map((inst, idx) => (
                                <tr key={inst.id ?? idx}>
                                    <td>
                                        <InstanceStatusBadge
                                            status={inst.status}
                                        />
                                    </td>
                                    <td className="text-sm text-muted-foreground tabular-nums">
                                        {inst.started_at
                                            ? formatDate(inst.started_at, {
                                                  dateStyle: 'medium',
                                                  timeStyle: 'short',
                                              })
                                            : '—'}
                                    </td>
                                    <td className="text-sm tabular-nums">
                                        {duration(
                                            inst.started_at,
                                            inst.finished_at,
                                        )}
                                    </td>
                                    <td className="text-sm text-muted-foreground">
                                        {inst.business_id ?? '—'}
                                    </td>
                                    <td className="max-w-xs truncate text-sm text-red-600 dark:text-red-400">
                                        {inst.error ?? '—'}
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

OicMonitorShow.layout = {
    breadcrumbs: (page: { props: Props }) => [
        { title: 'Connexions Oracle', href: oracleTenants.index() },
        { title: page.props.tenant.label, href: oracleTenants.index() },
        {
            title: 'Monitoring OIC',
            href: oicMonitor.index(page.props.tenant.id),
        },
        {
            title: page.props.integration?.name ?? page.props.integration_id,
            href: '',
        },
    ],
};
