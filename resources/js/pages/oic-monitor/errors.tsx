import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/i18n/i18n-context';
import oracleTenants from '@/routes/oracle-tenants';
import type { OicError, OicTenantShape } from '@/types';

const oicMonitor = {
    index: (id: number) => `/oracle-tenants/${id}/oic-monitor`,
    errors: (id: number) => `/oracle-tenants/${id}/oic-monitor/errors`,
};

type Props = {
    tenant: OicTenantShape;
    errors: OicError[];
    error: string | null;
};

export default function OicMonitorErrors({ tenant, errors, error }: Props) {
    const { t, formatDate } = useI18n();

    return (
        <>
            <Head title={t('oic.errorsTitle')} />

            <div className="page-header">
                <div className="flex items-center gap-3">
                    <Link href={oicMonitor.index(tenant.id)}>
                        <Button variant="ghost" size="icon" className="size-8">
                            <ArrowLeft className="size-4" />
                        </Button>
                    </Link>
                    <Heading
                        title={t('oic.errorsTitle')}
                        description={`${tenant.label} · ${t('oic.errorsDescription')}`}
                    />
                </div>
            </div>

            {error && (
                <div className="alert alert-error mb-6">
                    <AlertTriangle className="size-4 shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            {errors.length === 0 && !error ? (
                <div className="empty-state">
                    <p className="font-medium text-emerald-600 dark:text-emerald-400">
                        {t('oic.noErrors')}
                    </p>
                </div>
            ) : (
                <div className="card overflow-hidden">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>{t('oic.integrationName')}</th>
                                <th>{t('oic.startedAt')}</th>
                                <th>{t('oic.errorMessage')}</th>
                                <th>{t('oic.instanceId')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {errors.map((err, idx) => (
                                <tr key={err.instance_id ?? idx}>
                                    <td>
                                        <div className="font-medium">
                                            {err.integration_name ?? '—'}
                                        </div>
                                        <code className="text-xs text-muted-foreground">
                                            {err.integration_id}
                                        </code>
                                    </td>
                                    <td className="tabular-nums text-sm text-muted-foreground">
                                        {err.started_at
                                            ? formatDate(err.started_at, {
                                                  dateStyle: 'medium',
                                                  timeStyle: 'short',
                                              })
                                            : '—'}
                                    </td>
                                    <td className="max-w-sm text-sm text-red-600 dark:text-red-400">
                                        {err.error_message ?? '—'}
                                    </td>
                                    <td className="font-mono text-xs text-muted-foreground">
                                        {err.instance_id ?? '—'}
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

OicMonitorErrors.layout = {
    breadcrumbs: (page: { props: Props }) => [
        { title: 'Connexions Oracle', href: oracleTenants.index() },
        { title: page.props.tenant.label, href: oracleTenants.index() },
        {
            title: 'Monitoring OIC',
            href: oicMonitor.index(page.props.tenant.id),
        },
        {
            title: 'Erreurs',
            href: oicMonitor.errors(page.props.tenant.id),
        },
    ],
};
