import { Form, Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Database,
    Eye,
    GitBranch,
    History,
    RefreshCw,
    Search,
    TableProperties,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable, StopClick } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import oracleSchema from '@/routes/oracle-schema';
import oracleTenants from '@/routes/oracle-tenants';
import type {
    OracleSchemaDriftStatus,
    OracleSchemaPageProps,
    OracleTenantSchemaResource,
} from '@/types';

function DriftBadge({ status }: { status: OracleSchemaDriftStatus }) {
    const { t } = useI18n();
    const variant =
        status === 'changed'
            ? 'destructive'
            : status === 'current'
              ? 'secondary'
              : 'outline';
    const Icon =
        status === 'changed'
            ? AlertTriangle
            : status === 'current'
              ? CheckCircle2
              : Clock3;

    return (
        <Badge variant={variant}>
            <Icon aria-hidden="true" />
            {t(`oracleSchema.status.${status}`)}
        </Badge>
    );
}

function SyncResourceButton({
    tenantId,
    resourceKey,
    disabled,
}: {
    tenantId: number;
    resourceKey: string;
    disabled: boolean;
}) {
    const { t } = useI18n();

    return (
        <Form
            {...oracleSchema.sync.form(tenantId)}
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <div>
                    <input
                        type="hidden"
                        name="resource_key"
                        value={resourceKey}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={disabled || processing}
                    >
                        {processing ? (
                            <Spinner aria-hidden="true" />
                        ) : (
                            <RefreshCw aria-hidden="true" />
                        )}
                        {processing
                            ? t('oracleSchema.synchronizing')
                            : t('oracleSchema.synchronize')}
                    </Button>
                    <InputError
                        message={errors.resource_key ?? errors.synchronization}
                        className="mt-1"
                    />
                </div>
            )}
        </Form>
    );
}

function ResourceDetailsDialog({
    tenantId,
    resource,
    canAcknowledge,
}: {
    tenantId: number;
    resource: OracleTenantSchemaResource;
    canAcknowledge: boolean;
}) {
    const { t, formatDate } = useI18n();
    const hasDrift =
        resource.drift.added.length > 0 ||
        resource.drift.removed.length > 0 ||
        resource.drift.changed.length > 0;

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={t('oracleSchema.openDetailsFor', {
                        resource: resource.label,
                    })}
                >
                    <Eye aria-hidden="true" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>{resource.label}</DialogTitle>
                    <DialogDescription>
                        {t('oracleSchema.resourceDetailsDescription', {
                            key: resource.resource_key,
                        })}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-lg border p-4">
                        <p className="text-xs text-muted-foreground">
                            {t('oracleSchema.schemaStatus')}
                        </p>
                        <div className="mt-2">
                            <DriftBadge status={resource.drift_status} />
                        </div>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-xs text-muted-foreground">
                            {t('oracleSchema.schemaHash')}
                        </p>
                        <code className="mt-2 block truncate text-xs">
                            {resource.schema_hash ?? '—'}
                        </code>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-xs text-muted-foreground">
                            {t('oracleSchema.lastSynchronization')}
                        </p>
                        <p className="mt-2 text-sm font-medium">
                            {resource.discovered_at
                                ? formatDate(resource.discovered_at, {
                                      dateStyle: 'medium',
                                      timeStyle: 'short',
                                  })
                                : t('oracleSchema.neverSynchronized')}
                        </p>
                    </div>
                </div>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <AlertTriangle className="size-4" aria-hidden="true" />
                        <h3 className="font-semibold">
                            {t('oracleSchema.driftDetails')}
                        </h3>
                    </div>
                    {!hasDrift ? (
                        <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                            {t('oracleSchema.noDrift')}
                        </p>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-3">
                            {(
                                [
                                    [
                                        'added',
                                        resource.drift.added,
                                        'oracleSchema.addedFields',
                                    ],
                                    [
                                        'removed',
                                        resource.drift.removed,
                                        'oracleSchema.removedFields',
                                    ],
                                    [
                                        'changed',
                                        resource.drift.changed,
                                        'oracleSchema.changedFields',
                                    ],
                                ] as const
                            ).map(([key, values, label]) => (
                                <div
                                    key={key}
                                    className="rounded-lg border p-4"
                                >
                                    <p className="mb-3 text-sm font-medium">
                                        {t(label, { count: values.length })}
                                    </p>
                                    {values.length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            {t('oracleSchema.noChangedFields')}
                                        </p>
                                    ) : (
                                        <ul className="space-y-1">
                                            {values.map((field) => (
                                                <li key={field}>
                                                    <code className="text-xs">
                                                        {field}
                                                    </code>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <GitBranch className="size-4" aria-hidden="true" />
                        <h3 className="font-semibold">
                            {t('oracleSchema.impactsTitle')}
                        </h3>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="rounded-lg border p-4">
                            <p className="text-2xl font-semibold">
                                {resource.impacts.templates_count}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('oracleSchema.impactedTemplates')}
                            </p>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="text-2xl font-semibold">
                                {resource.impacts.queries_count}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('oracleSchema.impactedQueries')}
                            </p>
                        </div>
                        <div className="rounded-lg border p-4">
                            <p className="text-2xl font-semibold">
                                {resource.impacts.executions_count}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('oracleSchema.impactedExecutions')}
                            </p>
                        </div>
                    </div>
                    {resource.impacts.acknowledged_at && (
                        <p className="text-xs text-muted-foreground">
                            {t('oracleSchema.acknowledgedAt', {
                                date: formatDate(
                                    resource.impacts.acknowledged_at,
                                    {
                                        dateStyle: 'medium',
                                        timeStyle: 'short',
                                    },
                                ),
                            })}
                        </p>
                    )}
                    {canAcknowledge && resource.drift_status === 'changed' && (
                        <Form
                            {...oracleSchema.impacts.acknowledge.form([
                                tenantId,
                                resource.resource_key,
                            ])}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing && (
                                            <Spinner aria-hidden="true" />
                                        )}
                                        {t('oracleSchema.acknowledgeImpacts')}
                                    </Button>
                                    <InputError
                                        message={errors.impact}
                                        className="mt-1"
                                    />
                                </div>
                            )}
                        </Form>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <TableProperties
                            className="size-4"
                            aria-hidden="true"
                        />
                        <h3 className="font-semibold">
                            {t('oracleSchema.fieldsTitle', {
                                count: resource.fields.length,
                            })}
                        </h3>
                    </div>
                    {resource.attributes.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                            {t('oracleSchema.fieldsEmpty')}
                        </p>
                    ) : (
                        <div className="max-h-72 overflow-auto rounded-lg border">
                            <table className="w-full border-collapse text-sm">
                                <thead className="sticky top-0 bg-muted/90 backdrop-blur">
                                    <tr>
                                        <th className="border-b px-4 py-3 text-left font-medium">
                                            {t('oracleSchema.fieldName')}
                                        </th>
                                        <th className="border-b border-l px-4 py-3 text-left font-medium">
                                            {t('oracleSchema.fieldType')}
                                        </th>
                                        <th className="border-b border-l px-4 py-3 text-left font-medium">
                                            {t('oracleSchema.fieldProperties')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {resource.attributes.map((attribute) => (
                                        <tr key={attribute.name}>
                                            <td className="border-b px-4 py-3">
                                                <code className="text-xs">
                                                    {attribute.name}
                                                </code>
                                            </td>
                                            <td className="border-b border-l px-4 py-3">
                                                {attribute.type ?? '—'}
                                            </td>
                                            <td className="border-b border-l px-4 py-3">
                                                <div className="flex flex-wrap gap-1">
                                                    {attribute.mandatory && (
                                                        <Badge variant="outline">
                                                            {t(
                                                                'oracleSchema.mandatory',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {attribute.updatable && (
                                                        <Badge variant="outline">
                                                            {t(
                                                                'oracleSchema.updatable',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {attribute.max_length && (
                                                        <Badge variant="outline">
                                                            {t(
                                                                'oracleSchema.maxLength',
                                                                {
                                                                    value: attribute.max_length,
                                                                },
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center gap-2">
                        <History className="size-4" aria-hidden="true" />
                        <h3 className="font-semibold">
                            {t('oracleSchema.historyTitle')}
                        </h3>
                    </div>
                    {resource.history.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                            {t('oracleSchema.historyEmpty')}
                        </p>
                    ) : (
                        <ol className="divide-y rounded-lg border">
                            {resource.history.map((snapshot) => (
                                <li
                                    key={snapshot.id}
                                    className="grid gap-3 p-4 md:grid-cols-[minmax(12rem,1fr)_auto] md:items-center"
                                >
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium">
                                            {formatDate(snapshot.synced_at, {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            })}
                                        </p>
                                        <code className="block truncate text-xs text-muted-foreground">
                                            {snapshot.schema_hash}
                                        </code>
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        <Badge variant="outline">
                                            +{snapshot.diff.added.length}
                                        </Badge>
                                        <Badge variant="outline">
                                            −{snapshot.diff.removed.length}
                                        </Badge>
                                        <Badge variant="outline">
                                            ~{snapshot.diff.changed.length}
                                        </Badge>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {t('oracleSchema.closeDetails')}
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function OracleTenantSchema({
    tenant,
    resources,
    summary,
    capabilities,
}: OracleSchemaPageProps) {
    const { t, formatDate } = useI18n();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<OracleSchemaDriftStatus | 'all'>(
        'all',
    );
    const [domain, setDomain] = useState('all');
    const domains = useMemo(
        () => [...new Set(resources.map((resource) => resource.domain))].sort(),
        [resources],
    );
    const filteredResources = useMemo(() => {
        const normalizedSearch = search.trim().toLocaleLowerCase();

        return resources.filter((resource) => {
            const matchesSearch =
                normalizedSearch === '' ||
                resource.label.toLocaleLowerCase().includes(normalizedSearch) ||
                resource.resource_key
                    .toLocaleLowerCase()
                    .includes(normalizedSearch);
            const matchesStatus =
                status === 'all' || resource.drift_status === status;
            const matchesDomain =
                domain === 'all' || resource.domain === domain;

            return matchesSearch && matchesStatus && matchesDomain;
        });
    }, [domain, resources, search, status]);

    const columns = useMemo<DataTableColumn<OracleTenantSchemaResource>[]>(
        () => [
            {
                key: 'resource',
                header: t('oracleSchema.resource'),
                icon: Database,
                cell: (resource) => (
                    <div className="max-w-sm space-y-1 whitespace-normal">
                        <p className="font-medium">{resource.label}</p>
                        <code className="block text-xs text-muted-foreground">
                            {resource.resource_key}
                        </code>
                    </div>
                ),
            },
            {
                key: 'domain',
                header: t('oracleSchema.domain'),
                cell: (resource) => resource.domain,
            },
            {
                key: 'status',
                header: t('oracleSchema.schemaStatus'),
                icon: Activity,
                cell: (resource) => (
                    <DriftBadge status={resource.drift_status} />
                ),
            },
            {
                key: 'fields',
                header: t('oracleSchema.fields'),
                icon: TableProperties,
                align: 'right',
                cell: (resource) => resource.fields.length,
            },
            {
                key: 'drift',
                header: t('oracleSchema.drift'),
                icon: AlertTriangle,
                cell: (resource) => (
                    <div className="flex flex-wrap gap-1">
                        <Badge variant="outline">
                            +{resource.drift.added.length}
                        </Badge>
                        <Badge variant="outline">
                            −{resource.drift.removed.length}
                        </Badge>
                        <Badge variant="outline">
                            ~{resource.drift.changed.length}
                        </Badge>
                    </div>
                ),
            },
            {
                key: 'impacts',
                header: t('oracleSchema.impacts'),
                icon: GitBranch,
                align: 'right',
                cell: (resource) =>
                    resource.impacts.templates_count +
                    resource.impacts.queries_count,
            },
            {
                key: 'observed',
                header: t('oracleSchema.lastSynchronization'),
                cell: (resource) =>
                    resource.discovered_at
                        ? formatDate(resource.discovered_at, {
                              dateStyle: 'medium',
                          })
                        : t('oracleSchema.neverSynchronized'),
            },
            {
                key: 'actions',
                header: t('common.actions'),
                align: 'right',
                cell: (resource) => (
                    <StopClick>
                        <ResourceDetailsDialog
                            tenantId={tenant.id}
                            resource={resource}
                            canAcknowledge={capabilities.acknowledge_impacts}
                        />
                        <SyncResourceButton
                            tenantId={tenant.id}
                            resourceKey={resource.resource_key}
                            disabled={
                                !capabilities.synchronize || !tenant.is_active
                            }
                        />
                    </StopClick>
                ),
            },
        ],
        [
            capabilities.acknowledge_impacts,
            capabilities.synchronize,
            formatDate,
            t,
            tenant.id,
            tenant.is_active,
        ],
    );

    const stats = [
        {
            key: 'resources',
            icon: Database,
            value: summary.resources,
            label: t('oracleSchema.summaryResources'),
        },
        {
            key: 'synchronized',
            icon: CheckCircle2,
            value: summary.synchronized,
            label: t('oracleSchema.summarySynchronized'),
        },
        {
            key: 'drifted',
            icon: AlertTriangle,
            value: summary.drifted,
            label: t('oracleSchema.summaryDrifted'),
        },
        {
            key: 'impacted',
            icon: GitBranch,
            value: summary.impacted,
            label: t('oracleSchema.summaryImpacted'),
        },
    ];

    return (
        <>
            <Head title={t('oracleSchema.pageTitle')} />
            <h1 className="sr-only">{t('oracleSchema.pageTitle')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('oracleSchema.title')}
                    description={t('oracleSchema.description', {
                        tenant: tenant.label,
                    })}
                    actions={
                        <Button asChild size="sm" variant="outline">
                            <Link href={oracleTenants.index()}>
                                {t('oracleSchema.backToConnections')}
                            </Link>
                        </Button>
                    }
                />

                {!tenant.is_active && (
                    <Alert>
                        <AlertTriangle aria-hidden="true" />
                        <AlertTitle>
                            {t('oracleSchema.inactiveTenantTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('oracleSchema.inactiveTenantDescription')}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {stats.map((stat) => (
                        <Card key={stat.key} className="gap-3 py-4">
                            <CardContent className="flex items-center gap-3 px-4">
                                <span className="grid size-10 place-items-center rounded-lg bg-muted">
                                    <stat.icon
                                        className="size-5 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p className="text-2xl font-semibold">
                                        {stat.value}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {stat.label}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('oracleSchema.filtersTitle')}</CardTitle>
                        <CardDescription>
                            {t('oracleSchema.filtersDescription')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="oracle-schema-search">
                                {t('oracleSchema.search')}
                            </Label>
                            <div className="relative">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    id="oracle-schema-search"
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder={t(
                                        'oracleSchema.searchPlaceholder',
                                    )}
                                    className="pl-9"
                                />
                            </div>
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('oracleSchema.domain')}</Label>
                            <Select value={domain} onValueChange={setDomain}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        {t('oracleSchema.allDomains')}
                                    </SelectItem>
                                    {domains.map((item) => (
                                        <SelectItem key={item} value={item}>
                                            {item}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('oracleSchema.schemaStatus')}</Label>
                            <Select
                                value={status}
                                onValueChange={(value) =>
                                    setStatus(
                                        value as
                                            | OracleSchemaDriftStatus
                                            | 'all',
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        {t('oracleSchema.allStatuses')}
                                    </SelectItem>
                                    {(
                                        [
                                            'never_synced',
                                            'current',
                                            'changed',
                                            'acknowledged',
                                        ] as OracleSchemaDriftStatus[]
                                    ).map((item) => (
                                        <SelectItem key={item} value={item}>
                                            {t(`oracleSchema.status.${item}`)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('oracleSchema.resourcesTitle')}
                        </CardTitle>
                        <CardDescription>
                            {t('oracleSchema.resourcesDescription', {
                                visible: filteredResources.length,
                                total: resources.length,
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-hidden rounded-lg border">
                            <DataTable
                                columns={columns}
                                rows={filteredResources}
                                rowKey={(resource) => resource.resource_key}
                                empty={
                                    <div className="space-y-1">
                                        <p className="font-medium text-foreground">
                                            {t('oracleSchema.resourcesEmpty')}
                                        </p>
                                        <p>
                                            {t(
                                                'oracleSchema.resourcesEmptyDescription',
                                            )}
                                        </p>
                                    </div>
                                }
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
