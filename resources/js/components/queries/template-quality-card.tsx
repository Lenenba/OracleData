import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Camera,
    CheckCircle2,
    CircleHelp,
    Clock3,
    Database,
    Gauge,
    Play,
    RefreshCw,
    ShieldAlert,
    Timer,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import { store as storeQualityRun } from '@/routes/query-template-governance/versions/quality-runs';
import { store as storeReferenceDataset } from '@/routes/query-template-governance/versions/reference-datasets';
import type {
    QueryTemplateQualityHealthStatus,
    QueryTemplateQualityOverview,
    QueryTemplateQualityRun,
    QueryTemplateQualityRunStatus,
    QueryTemplateReferenceDataset,
    QueryTemplateReferenceScenario,
} from '@/types/query-template-governance';

type Props = {
    templateSlug: string;
    version: { id: number; version_number: number } | null;
    quality: QueryTemplateQualityOverview;
    tenants: Record<string, string>;
    defaultTenant: string | null;
    canRunValidation: boolean;
    canCaptureReference: boolean;
};

type Feedback = { type: 'success' | 'error'; message: string } | null;

const REFERENCE_SCENARIOS: QueryTemplateReferenceScenario[] = [
    'baseline',
    'filter',
    'join',
    'duplicates',
    'order',
    'aggregate',
    'nulls',
];

function firstError(errors: Record<string, string>, fallback: string) {
    return Object.values(errors)[0] ?? fallback;
}

function parseJsonObject(
    source: string,
    invalidMessage: string,
    objectMessage: string,
): { value: Record<string, unknown> | null; error: string | null } {
    let value: unknown;

    try {
        value = JSON.parse(source);
    } catch {
        return { value: null, error: invalidMessage };
    }

    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return { value: null, error: objectMessage };
    }

    return { value: value as Record<string, unknown>, error: null };
}

function QualityRunStatusBadge({
    status,
}: {
    status: QueryTemplateQualityRunStatus;
}) {
    const { t } = useI18n();
    const labels: Record<QueryTemplateQualityRunStatus, string> = {
        passed: t('templateQuality.runStatus.passed'),
        failed: t('templateQuality.runStatus.failed'),
        error: t('templateQuality.runStatus.error'),
    };

    return (
        <Badge
            variant={
                status === 'passed'
                    ? 'default'
                    : status === 'failed'
                      ? 'secondary'
                      : 'destructive'
            }
            className={
                status === 'passed'
                    ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-600'
                    : status === 'failed'
                      ? 'border-amber-300 bg-amber-100 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100'
                      : undefined
            }
        >
            {status === 'passed' ? (
                <CheckCircle2 aria-hidden="true" />
            ) : status === 'failed' ? (
                <AlertTriangle aria-hidden="true" />
            ) : (
                <XCircle aria-hidden="true" />
            )}
            {labels[status]}
        </Badge>
    );
}

function HealthStatusBadge({
    status,
}: {
    status: QueryTemplateQualityHealthStatus;
}) {
    const { t } = useI18n();
    const labels: Record<QueryTemplateQualityHealthStatus, string> = {
        unknown: t('templateQuality.healthStatus.unknown'),
        healthy: t('templateQuality.healthStatus.healthy'),
        degraded: t('templateQuality.healthStatus.degraded'),
        failing: t('templateQuality.healthStatus.failing'),
    };

    return (
        <Badge
            variant={status === 'failing' ? 'destructive' : 'outline'}
            className={
                status === 'healthy'
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                    : status === 'degraded'
                      ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100'
                      : undefined
            }
        >
            {labels[status]}
        </Badge>
    );
}

function purposeLabel(purpose: string, t: ReturnType<typeof useI18n>['t']) {
    if (purpose === 'pre_publication') {
        return t('templateQuality.purpose.prePublication');
    }

    if (purpose === 'monitoring') {
        return t('templateQuality.purpose.monitoring');
    }

    if (purpose === 'manual') {
        return t('templateQuality.purpose.manual');
    }

    return purpose;
}

function RunSummary({ run }: { run: QueryTemplateQualityRun }) {
    const { t, formatDate, formatNumber } = useI18n();

    return (
        <div className="grid gap-3 rounded-lg border bg-muted/20 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('templateQuality.latestRunStatus')}
                </p>
                <QualityRunStatusBadge status={run.status} />
            </div>
            <div className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('templateQuality.latestRunAssertions')}
                </p>
                <p className="font-medium">
                    {t('templateQuality.assertionSummary', {
                        passed: run.assertions_passed,
                        failed: run.assertions_failed,
                    })}
                </p>
            </div>
            <div className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('templateQuality.latestRunPerformance')}
                </p>
                <p className="font-medium">
                    {formatNumber(run.duration_ms)} ms ·{' '}
                    {t('templateQuality.rowCount', {
                        count: formatNumber(run.rows_count),
                    })}
                </p>
            </div>
            <div className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t('templateQuality.latestRunDate')}
                </p>
                <p className="font-medium">
                    {formatDate(run.finished_at, {
                        dateStyle: 'medium',
                        timeStyle: 'short',
                    })}
                </p>
            </div>
        </div>
    );
}

function ReferenceDatasetItem({
    reference,
}: {
    reference: QueryTemplateReferenceDataset;
}) {
    const { t, formatDate, formatNumber } = useI18n();

    return (
        <li className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-medium">{reference.name}</p>
                    <p className="text-sm text-muted-foreground">
                        {reference.scenario}
                    </p>
                </div>
                <Badge variant="outline">
                    {t('templateQuality.versionBadge', {
                        version: reference.version_number,
                    })}
                </Badge>
            </div>
            <dl className="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('templateQuality.referenceTenant')}
                    </dt>
                    <dd>{reference.tenant_key ?? '—'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('templateQuality.referenceRows')}
                    </dt>
                    <dd>{formatNumber(reference.rows_count)}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        {t('templateQuality.referenceCapturedAt')}
                    </dt>
                    <dd>
                        {formatDate(reference.captured_at, {
                            dateStyle: 'medium',
                            timeStyle: 'short',
                        })}
                    </dd>
                </div>
            </dl>
            <div className="space-y-1">
                <p className="text-xs text-muted-foreground">
                    {t('templateQuality.referenceHash')}
                </p>
                <code
                    className="block truncate rounded bg-muted px-2 py-1 text-xs"
                    title={reference.dataset_hash}
                >
                    {reference.dataset_hash}
                </code>
            </div>
            {reference.captured_by && (
                <p className="text-xs text-muted-foreground">
                    {t('templateQuality.referenceCapturedBy', {
                        name: reference.captured_by.name,
                    })}
                </p>
            )}
        </li>
    );
}

export function TemplateQualityCard({
    templateSlug,
    version,
    quality,
    tenants,
    defaultTenant,
    canRunValidation,
    canCaptureReference,
}: Props) {
    const { t, formatDate, formatNumber } = useI18n();
    const tenantEntries = useMemo(() => Object.entries(tenants), [tenants]);
    const initialTenant =
        defaultTenant !== null && tenants[defaultTenant] !== undefined
            ? defaultTenant
            : (tenantEntries[0]?.[0] ?? '');
    const [runTenant, setRunTenant] = useState(initialTenant);
    const [runParametersJson, setRunParametersJson] = useState('{}');
    const [referenceDatasetId, setReferenceDatasetId] = useState('none');
    const [runProcessing, setRunProcessing] = useState(false);
    const [runErrors, setRunErrors] = useState<Record<string, string>>({});
    const [runFeedback, setRunFeedback] = useState<Feedback>(null);
    const [referenceName, setReferenceName] = useState('');
    const [referenceScenario, setReferenceScenario] =
        useState<QueryTemplateReferenceScenario>('baseline');
    const [referenceTenant, setReferenceTenant] = useState(initialTenant);
    const [referenceParametersJson, setReferenceParametersJson] =
        useState('{}');
    const [comparisonConfigJson, setComparisonConfigJson] = useState(() =>
        JSON.stringify(
            {
                order_sensitive: true,
                duplicate_sensitive: true,
                compare_nulls: true,
                compare_schema: true,
                nested_order_sensitive: true,
            },
            null,
            2,
        ),
    );
    const [referenceProcessing, setReferenceProcessing] = useState(false);
    const [referenceErrors, setReferenceErrors] = useState<
        Record<string, string>
    >({});
    const [referenceFeedback, setReferenceFeedback] = useState<Feedback>(null);
    const health = quality.health;
    const compatibleReferences = useMemo(
        () =>
            version === null
                ? []
                : quality.references.filter(
                      (reference) =>
                          reference.version_number === version.version_number,
                  ),
        [quality.references, version],
    );
    const healthScore =
        health.score === null ? null : Math.max(0, Math.min(100, health.score));

    function submitQualityRun(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setRunErrors({});
        setRunFeedback(null);

        if (version === null) {
            return;
        }

        const clientErrors: Record<string, string> = {};
        const parameters = parseJsonObject(
            runParametersJson,
            t('templateQuality.parameterValuesJsonInvalid'),
            t('templateQuality.parameterValuesObjectRequired'),
        );

        if (runTenant === '') {
            clientErrors.tenant = t('templateQuality.tenantRequired');
        }

        if (parameters.error !== null) {
            clientErrors.parameter_values = parameters.error;
        }

        if (Object.keys(clientErrors).length > 0 || parameters.value === null) {
            setRunErrors(clientErrors);
            setRunFeedback({
                type: 'error',
                message: t('templateQuality.formJsonError'),
            });

            return;
        }

        router.post(
            storeQualityRun.url([templateSlug, version.id]),
            {
                tenant: runTenant,
                parameter_values: parameters.value,
                ...(referenceDatasetId === 'none'
                    ? {}
                    : { reference_dataset_id: Number(referenceDatasetId) }),
            } as NonNullable<Parameters<typeof router.post>[1]>,
            {
                preserveScroll: true,
                onStart: () => setRunProcessing(true),
                onError: (errors) => {
                    setRunErrors(errors);
                    setRunFeedback({
                        type: 'error',
                        message: firstError(
                            errors,
                            t('templateQuality.runError'),
                        ),
                    });
                },
                onSuccess: () =>
                    setRunFeedback({
                        type: 'success',
                        message: t('templateQuality.runSuccess'),
                    }),
                onFinish: () => setRunProcessing(false),
            },
        );
    }

    function captureReference(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setReferenceErrors({});
        setReferenceFeedback(null);

        if (version === null) {
            return;
        }

        const clientErrors: Record<string, string> = {};
        const parameters = parseJsonObject(
            referenceParametersJson,
            t('templateQuality.parameterValuesJsonInvalid'),
            t('templateQuality.parameterValuesObjectRequired'),
        );
        const comparisonConfig = parseJsonObject(
            comparisonConfigJson,
            t('templateQuality.comparisonConfigJsonInvalid'),
            t('templateQuality.comparisonConfigObjectRequired'),
        );

        if (referenceName.trim() === '') {
            clientErrors.name = t('templateQuality.referenceNameRequired');
        }

        if (referenceTenant === '') {
            clientErrors.tenant = t('templateQuality.tenantRequired');
        }

        if (parameters.error !== null) {
            clientErrors.parameter_values = parameters.error;
        }

        if (comparisonConfig.error !== null) {
            clientErrors.comparison_config = comparisonConfig.error;
        }

        if (
            Object.keys(clientErrors).length > 0 ||
            parameters.value === null ||
            comparisonConfig.value === null
        ) {
            setReferenceErrors(clientErrors);
            setReferenceFeedback({
                type: 'error',
                message: t('templateQuality.formJsonError'),
            });

            return;
        }

        router.post(
            storeReferenceDataset.url([templateSlug, version.id]),
            {
                name: referenceName.trim(),
                scenario: referenceScenario,
                comparison_config: comparisonConfig.value,
                tenant: referenceTenant,
                parameter_values: parameters.value,
            } as NonNullable<Parameters<typeof router.post>[1]>,
            {
                preserveScroll: true,
                onStart: () => setReferenceProcessing(true),
                onError: (errors) => {
                    setReferenceErrors(errors);
                    setReferenceFeedback({
                        type: 'error',
                        message: firstError(
                            errors,
                            t('templateQuality.referenceCaptureError'),
                        ),
                    });
                },
                onSuccess: () => {
                    setReferenceName('');
                    setReferenceScenario('baseline');
                    setReferenceFeedback({
                        type: 'success',
                        message: t('templateQuality.referenceCaptureSuccess'),
                    });
                },
                onFinish: () => setReferenceProcessing(false),
            },
        );
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            <Activity aria-hidden="true" />
                            {t('templateQuality.title')}
                        </CardTitle>
                        <CardDescription>
                            {t('templateQuality.description')}
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {version && (
                            <Badge variant="outline">
                                {t('templateQuality.versionBadge', {
                                    version: version.version_number,
                                })}
                            </Badge>
                        )}
                        <HealthStatusBadge status={health.status} />
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-8">
                {health.certification_suspended && (
                    <Alert variant="destructive">
                        <ShieldAlert aria-hidden="true" />
                        <AlertTitle>
                            {t('templateQuality.certificationSuspendedTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            {t(
                                'templateQuality.certificationSuspendedDescription',
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <section
                    aria-labelledby="template-quality-health-title"
                    className="space-y-4"
                >
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <h3
                                id="template-quality-health-title"
                                className="font-semibold"
                            >
                                {t('templateQuality.healthTitle')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {health.last_run_at
                                    ? t('templateQuality.lastEvaluatedAt', {
                                          date: formatDate(health.last_run_at, {
                                              dateStyle: 'medium',
                                              timeStyle: 'short',
                                          }),
                                      })
                                    : t('templateQuality.neverEvaluated')}
                            </p>
                        </div>
                        <div className="text-right">
                            <p className="text-sm text-muted-foreground">
                                {t('templateQuality.healthScore')}
                            </p>
                            <p className="text-3xl font-semibold tabular-nums">
                                {healthScore === null
                                    ? '—'
                                    : `${formatNumber(healthScore)} / 100`}
                            </p>
                        </div>
                    </div>
                    <div
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-label={t('templateQuality.healthScore')}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={healthScore ?? undefined}
                    >
                        <div
                            className={
                                health.status === 'healthy'
                                    ? 'h-full bg-emerald-600 transition-[width]'
                                    : health.status === 'degraded'
                                      ? 'h-full bg-amber-500 transition-[width]'
                                      : health.status === 'failing'
                                        ? 'h-full bg-destructive transition-[width]'
                                        : 'h-full bg-muted-foreground/40 transition-[width]'
                            }
                            style={{ width: `${healthScore ?? 0}%` }}
                        />
                    </div>
                    <ul className="grid gap-3 sm:grid-cols-3">
                        {[
                            {
                                active: health.is_slow,
                                label: t('templateQuality.slowLabel'),
                                description: t(
                                    health.is_slow
                                        ? 'templateQuality.slowDetected'
                                        : 'templateQuality.slowClear',
                                ),
                                icon: Timer,
                            },
                            {
                                active: health.is_broken,
                                label: t('templateQuality.brokenLabel'),
                                description: t(
                                    health.is_broken
                                        ? 'templateQuality.brokenDetected'
                                        : 'templateQuality.brokenClear',
                                ),
                                icon: XCircle,
                            },
                            {
                                active: health.is_stale,
                                label: t('templateQuality.staleLabel'),
                                description: t(
                                    health.is_stale
                                        ? 'templateQuality.staleDetected'
                                        : 'templateQuality.staleClear',
                                ),
                                icon: Clock3,
                            },
                        ].map((indicator) => {
                            const Icon = indicator.icon;

                            return (
                                <li
                                    key={indicator.label}
                                    className="flex gap-3 rounded-lg border p-3"
                                >
                                    <Icon
                                        className={
                                            indicator.active
                                                ? 'mt-0.5 size-5 shrink-0 text-destructive'
                                                : 'mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400'
                                        }
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p className="text-sm font-medium">
                                            {indicator.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {indicator.description}
                                        </p>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    {quality.latest_run ? (
                        <RunSummary run={quality.latest_run} />
                    ) : (
                        <div className="rounded-lg border border-dashed px-4 py-8 text-center">
                            <Gauge
                                className="mx-auto mb-3 size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                {t('templateQuality.noLatestRun')}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t('templateQuality.noLatestRunDescription')}
                            </p>
                        </div>
                    )}
                </section>

                {canRunValidation && (
                    <section
                        aria-labelledby="template-quality-run-form-title"
                        className="space-y-4 border-t pt-6"
                    >
                        <div>
                            <h3
                                id="template-quality-run-form-title"
                                className="font-semibold"
                            >
                                {t('templateQuality.runFormTitle')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {t('templateQuality.runFormDescription')}
                            </p>
                        </div>
                        {version === null ? (
                            <Alert>
                                <CircleHelp aria-hidden="true" />
                                <AlertTitle>
                                    {t('templateQuality.noVersionTitle')}
                                </AlertTitle>
                                <AlertDescription>
                                    {t('templateQuality.noVersionDescription')}
                                </AlertDescription>
                            </Alert>
                        ) : tenantEntries.length === 0 ? (
                            <Alert>
                                <Database aria-hidden="true" />
                                <AlertTitle>
                                    {t('templateQuality.noTenantTitle')}
                                </AlertTitle>
                                <AlertDescription>
                                    {t('templateQuality.noTenantDescription')}
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <form
                                onSubmit={submitQualityRun}
                                className="space-y-4 rounded-lg border p-4"
                            >
                                {runFeedback && (
                                    <Alert
                                        variant={
                                            runFeedback.type === 'error'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        aria-live={
                                            runFeedback.type === 'error'
                                                ? 'assertive'
                                                : 'polite'
                                        }
                                    >
                                        <CircleHelp aria-hidden="true" />
                                        <AlertTitle>
                                            {runFeedback.type === 'error'
                                                ? t(
                                                      'templateGovernance.errorTitle',
                                                  )
                                                : t(
                                                      'templateGovernance.successTitle',
                                                  )}
                                        </AlertTitle>
                                        <AlertDescription>
                                            {runFeedback.message}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-run-tenant">
                                            {t('templateQuality.tenantLabel')}
                                        </Label>
                                        <select
                                            id="quality-run-tenant"
                                            value={runTenant}
                                            onChange={(event) =>
                                                setRunTenant(event.target.value)
                                            }
                                            disabled={runProcessing}
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30"
                                        >
                                            {tenantEntries.map(
                                                ([key, label]) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={runErrors.tenant}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-run-reference">
                                            {t(
                                                'templateQuality.referenceDatasetLabel',
                                            )}
                                        </Label>
                                        <select
                                            id="quality-run-reference"
                                            value={referenceDatasetId}
                                            onChange={(event) =>
                                                setReferenceDatasetId(
                                                    event.target.value,
                                                )
                                            }
                                            disabled={runProcessing}
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30"
                                        >
                                            <option value="none">
                                                {t(
                                                    'templateQuality.noReferenceDataset',
                                                )}
                                            </option>
                                            {compatibleReferences.map(
                                                (reference) => (
                                                    <option
                                                        key={reference.id}
                                                        value={reference.id}
                                                    >
                                                        {reference.name} —{' '}
                                                        {reference.scenario}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={
                                                runErrors.reference_dataset_id
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="quality-run-parameters">
                                        {t(
                                            'templateQuality.parameterValuesJsonLabel',
                                        )}
                                    </Label>
                                    <Textarea
                                        id="quality-run-parameters"
                                        value={runParametersJson}
                                        onChange={(event) =>
                                            setRunParametersJson(
                                                event.target.value,
                                            )
                                        }
                                        rows={6}
                                        spellCheck={false}
                                        disabled={runProcessing}
                                        className="font-mono text-xs"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'templateQuality.parameterValuesJsonHelp',
                                        )}
                                    </p>
                                    <InputError
                                        message={runErrors.parameter_values}
                                    />
                                </div>
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={runProcessing}
                                    >
                                        {runProcessing && (
                                            <Spinner
                                                aria-label={t('common.loading')}
                                            />
                                        )}
                                        <Play aria-hidden="true" />
                                        {t('templateQuality.runAction')}
                                    </Button>
                                </div>
                            </form>
                        )}
                    </section>
                )}

                <section
                    aria-labelledby="template-quality-history-title"
                    className="space-y-4 border-t pt-6"
                >
                    <div>
                        <h3
                            id="template-quality-history-title"
                            className="font-semibold"
                        >
                            {t('templateQuality.historyTitle')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('templateQuality.historyDescription')}
                        </p>
                    </div>
                    {quality.runs.length === 0 ? (
                        <div className="rounded-lg border border-dashed px-4 py-8 text-center">
                            <Activity
                                className="mx-auto mb-3 size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                {t('templateQuality.historyEmpty')}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-3xl text-left text-sm">
                                <caption className="sr-only">
                                    {t('templateQuality.historyCaption')}
                                </caption>
                                <thead className="border-b bg-muted/40 text-xs text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            {t('templateQuality.historyResult')}
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            {t(
                                                'templateQuality.historyContext',
                                            )}
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            {t(
                                                'templateQuality.historyAssertions',
                                            )}
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            {t(
                                                'templateQuality.historyMetrics',
                                            )}
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            {t('templateQuality.historyDate')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {quality.runs.map((run) => {
                                        const requiredFailed =
                                            run.assertion_results.filter(
                                                (assertion) =>
                                                    assertion.required &&
                                                    !assertion.passed,
                                            ).length;

                                        return (
                                            <tr key={run.id}>
                                                <td className="px-4 py-3 align-top">
                                                    <div className="space-y-2">
                                                        <QualityRunStatusBadge
                                                            status={run.status}
                                                        />
                                                        <p className="text-xs text-muted-foreground">
                                                            {t(
                                                                'templateQuality.scoreValue',
                                                                {
                                                                    score:
                                                                        run.score ??
                                                                        '—',
                                                                },
                                                            )}
                                                        </p>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 align-top">
                                                    <p className="font-medium">
                                                        {t(
                                                            'templateQuality.versionBadge',
                                                            {
                                                                version:
                                                                    run.version_number,
                                                            },
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {purposeLabel(
                                                            run.purpose,
                                                            t,
                                                        )}{' '}
                                                        · {run.tenant_key}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 align-top">
                                                    <p>
                                                        {t(
                                                            'templateQuality.assertionSummary',
                                                            {
                                                                passed: run.assertions_passed,
                                                                failed: run.assertions_failed,
                                                            },
                                                        )}
                                                    </p>
                                                    {requiredFailed > 0 && (
                                                        <p className="text-xs text-destructive">
                                                            {t(
                                                                'templateQuality.requiredFailed',
                                                                {
                                                                    count: requiredFailed,
                                                                },
                                                            )}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 align-top tabular-nums">
                                                    <p>
                                                        {formatNumber(
                                                            run.duration_ms,
                                                        )}{' '}
                                                        ms
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            'templateQuality.rowCount',
                                                            {
                                                                count: formatNumber(
                                                                    run.rows_count,
                                                                ),
                                                            },
                                                        )}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                                    {formatDate(
                                                        run.finished_at,
                                                        {
                                                            dateStyle: 'medium',
                                                            timeStyle: 'short',
                                                        },
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="template-quality-references-title"
                    className="space-y-4 border-t pt-6"
                >
                    <div>
                        <h3
                            id="template-quality-references-title"
                            className="font-semibold"
                        >
                            {t('templateQuality.referencesTitle')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('templateQuality.referencesDescription')}
                        </p>
                    </div>
                    {quality.references.length === 0 ? (
                        <div className="rounded-lg border border-dashed px-4 py-8 text-center">
                            <Database
                                className="mx-auto mb-3 size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                {t('templateQuality.referencesEmpty')}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'templateQuality.referencesEmptyDescription',
                                )}
                            </p>
                        </div>
                    ) : (
                        <ul className="grid gap-3 lg:grid-cols-2">
                            {quality.references.map((reference) => (
                                <ReferenceDatasetItem
                                    key={reference.id}
                                    reference={reference}
                                />
                            ))}
                        </ul>
                    )}

                    {canCaptureReference &&
                        version &&
                        tenantEntries.length > 0 && (
                            <form
                                onSubmit={captureReference}
                                className="space-y-4 rounded-lg border p-4"
                            >
                                <div>
                                    <h4 className="font-medium">
                                        {t('templateQuality.captureTitle')}
                                    </h4>
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'templateQuality.captureDescription',
                                        )}
                                    </p>
                                </div>
                                {referenceFeedback && (
                                    <Alert
                                        variant={
                                            referenceFeedback.type === 'error'
                                                ? 'destructive'
                                                : 'default'
                                        }
                                        aria-live={
                                            referenceFeedback.type === 'error'
                                                ? 'assertive'
                                                : 'polite'
                                        }
                                    >
                                        <CircleHelp aria-hidden="true" />
                                        <AlertTitle>
                                            {referenceFeedback.type === 'error'
                                                ? t(
                                                      'templateGovernance.errorTitle',
                                                  )
                                                : t(
                                                      'templateGovernance.successTitle',
                                                  )}
                                        </AlertTitle>
                                        <AlertDescription>
                                            {referenceFeedback.message}
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <div className="grid gap-4 lg:grid-cols-3">
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-reference-name">
                                            {t(
                                                'templateQuality.referenceNameLabel',
                                            )}
                                        </Label>
                                        <Input
                                            id="quality-reference-name"
                                            value={referenceName}
                                            onChange={(event) =>
                                                setReferenceName(
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={120}
                                            required
                                            disabled={referenceProcessing}
                                        />
                                        <InputError
                                            message={referenceErrors.name}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-reference-scenario">
                                            {t(
                                                'templateQuality.referenceScenarioLabel',
                                            )}
                                        </Label>
                                        <select
                                            id="quality-reference-scenario"
                                            value={referenceScenario}
                                            onChange={(event) =>
                                                setReferenceScenario(
                                                    event.target
                                                        .value as QueryTemplateReferenceScenario,
                                                )
                                            }
                                            disabled={referenceProcessing}
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30"
                                        >
                                            {REFERENCE_SCENARIOS.map(
                                                (scenario) => (
                                                    <option
                                                        key={scenario}
                                                        value={scenario}
                                                    >
                                                        {t(
                                                            `templateQuality.scenario.${scenario}`,
                                                        )}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={referenceErrors.scenario}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-reference-tenant">
                                            {t('templateQuality.tenantLabel')}
                                        </Label>
                                        <select
                                            id="quality-reference-tenant"
                                            value={referenceTenant}
                                            onChange={(event) =>
                                                setReferenceTenant(
                                                    event.target.value,
                                                )
                                            }
                                            disabled={referenceProcessing}
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30"
                                        >
                                            {tenantEntries.map(
                                                ([key, label]) => (
                                                    <option
                                                        key={key}
                                                        value={key}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={referenceErrors.tenant}
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-reference-parameters">
                                            {t(
                                                'templateQuality.parameterValuesJsonLabel',
                                            )}
                                        </Label>
                                        <Textarea
                                            id="quality-reference-parameters"
                                            value={referenceParametersJson}
                                            onChange={(event) =>
                                                setReferenceParametersJson(
                                                    event.target.value,
                                                )
                                            }
                                            rows={7}
                                            spellCheck={false}
                                            disabled={referenceProcessing}
                                            className="font-mono text-xs"
                                        />
                                        <InputError
                                            message={
                                                referenceErrors.parameter_values
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="quality-reference-comparison">
                                            {t(
                                                'templateQuality.comparisonConfigJsonLabel',
                                            )}
                                        </Label>
                                        <Textarea
                                            id="quality-reference-comparison"
                                            value={comparisonConfigJson}
                                            onChange={(event) =>
                                                setComparisonConfigJson(
                                                    event.target.value,
                                                )
                                            }
                                            rows={7}
                                            spellCheck={false}
                                            disabled={referenceProcessing}
                                            className="font-mono text-xs"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {t(
                                                'templateQuality.comparisonConfigJsonHelp',
                                            )}
                                        </p>
                                        <InputError
                                            message={
                                                referenceErrors.comparison_config
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={referenceProcessing}
                                    >
                                        {referenceProcessing && (
                                            <Spinner
                                                aria-label={t('common.loading')}
                                            />
                                        )}
                                        <Camera aria-hidden="true" />
                                        {t('templateQuality.captureAction')}
                                    </Button>
                                </div>
                            </form>
                        )}
                </section>

                {(runFeedback?.type === 'error' ||
                    referenceFeedback?.type === 'error') && (
                    <div className="flex justify-end border-t pt-4">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => router.reload({ only: ['quality'] })}
                        >
                            <RefreshCw aria-hidden="true" />
                            {t('templateQuality.reloadQuality')}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
