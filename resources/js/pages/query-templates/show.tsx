import { Head, router } from '@inertiajs/react';
import { Copy, Eye, LockKeyhole, Play } from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import Heading from '@/components/heading';
import { QueryResultView } from '@/components/queries/query-result';
import type { QueryResult } from '@/components/queries/query-result';
import { QueryTemplateParameters } from '@/components/queries/query-template-parameters';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import queryTemplates from '@/routes/query-templates';
import type {
    QueryTemplateSummary,
    QueryTemplateValue,
    QueryTemplateValues,
} from '@/types/query-template';

type RequestErrors = Record<string, string[] | string>;

function initialValues(template: QueryTemplateSummary): QueryTemplateValues {
    return Object.fromEntries(
        template.parameter_definitions.map((definition) => [
            definition.key,
            definition.default ?? '',
        ]),
    );
}

function normaliseErrors(errors: RequestErrors): {
    fields: Record<string, string>;
    all: string[];
} {
    const fields: Record<string, string> = {};
    const all: string[] = [];

    Object.entries(errors).forEach(([key, messages]) => {
        const message = Array.isArray(messages) ? messages[0] : messages;

        if (!message) {
            return;
        }

        all.push(message);

        if (key.startsWith('parameter_values.')) {
            fields[key.slice('parameter_values.'.length)] = message;
        }
    });

    return { fields, all };
}

export default function ShowQueryTemplate({
    template,
    tenants,
    defaultTenant,
}: {
    template: QueryTemplateSummary;
    tenants: Record<string, string>;
    defaultTenant: string;
}) {
    const { t } = useI18n();
    const tenantKeys = Object.keys(tenants);
    const [tenant, setTenantState] = useState(
        tenantKeys.includes(defaultTenant)
            ? defaultTenant
            : (tenantKeys[0] ?? ''),
    );
    const [values, setValues] = useState<QueryTemplateValues>(() =>
        initialValues(template),
    );
    const [status, setStatus] = useState<
        'idle' | 'previewing' | 'running' | 'done'
    >('idle');
    const [result, setResult] = useState<QueryResult | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [cloning, setCloning] = useState(false);
    const busy = status === 'previewing' || status === 'running';
    const tenantLabel = tenants[result?.tenant ?? tenant] ?? tenant;

    function resetResult() {
        setStatus('idle');
        setResult(null);
        setErrors([]);
        setFieldErrors({});
    }

    function setTenant(next: string) {
        setTenantState(next);
        resetResult();
    }

    function setValue(key: string, value: QueryTemplateValue) {
        setValues((current) => ({ ...current, [key]: value }));
        setStatus('idle');
        setResult(null);
        setErrors([]);
        setFieldErrors((current) => {
            const next = { ...current };
            delete next[key];

            return next;
        });
    }

    async function execute(kind: 'preview' | 'run') {
        setStatus(kind === 'preview' ? 'previewing' : 'running');
        setResult(null);
        setErrors([]);
        setFieldErrors({});

        const url =
            kind === 'preview'
                ? queryTemplates.preview.url(template.slug)
                : queryTemplates.run.url(template.slug);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    tenant,
                    parameter_values: values,
                }),
            });
            const data = (await response.json().catch(() => null)) as
                | (QueryResult & {
                      message?: string;
                      errors?: RequestErrors;
                  })
                | null;

            if (!response.ok || data === null) {
                const validation = normaliseErrors(data?.errors ?? {});
                setFieldErrors(validation.fields);
                setErrors(
                    validation.all.length > 0
                        ? validation.all
                        : [data?.message ?? t('templates.executionError')],
                );
                setStatus('done');

                return;
            }

            setResult(data);
            setStatus('done');
        } catch {
            setErrors([t('queries.networkError')]);
            setStatus('done');
        }
    }

    function createCopy() {
        setCloning(true);
        setErrors([]);
        setFieldErrors({});

        router.post(
            queryTemplates.clone.url(template.slug),
            { tenant, parameter_values: values },
            {
                preserveScroll: true,
                onError: (responseErrors) => {
                    const validation = normaliseErrors(responseErrors);
                    setFieldErrors(validation.fields);
                    setErrors(validation.all);
                    setStatus('done');
                },
                onFinish: () => setCloning(false),
            },
        );
    }

    return (
        <>
            <Head title={template.name} />

            <div className="space-y-6 px-6 py-6">
                <Heading
                    title={template.name}
                    description={template.description ?? undefined}
                    actions={
                        <Button
                            type="button"
                            size="sm"
                            onClick={createCopy}
                            disabled={cloning || busy || tenant === ''}
                        >
                            {cloning ? (
                                <Spinner />
                            ) : (
                                <Copy className="size-4" />
                            )}
                            {cloning
                                ? t('templates.copying')
                                : t('templates.createCopy')}
                        </Button>
                    }
                />

                <Alert>
                    <LockKeyhole />
                    <AlertTitle>{t('templates.immutable')}</AlertTitle>
                    <AlertDescription>
                        {t('templates.immutableDescription')}
                    </AlertDescription>
                </Alert>

                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <Badge variant="secondary">{t('templates.badge')}</Badge>
                    {template.category && (
                        <Badge
                            variant="outline"
                            style={
                                template.category.color
                                    ? {
                                          borderColor: template.category.color,
                                          color: template.category.color,
                                      }
                                    : undefined
                            }
                        >
                            {template.category.name}
                        </Badge>
                    )}
                    <Badge variant="outline">
                        {template.resource?.label ?? template.resource_key}
                    </Badge>
                    <code className="text-xs">{template.resource_path}</code>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t('templates.customParameters')}</CardTitle>
                        <CardDescription>
                            {t('templates.customParametersDescription')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <QueryTemplateParameters
                            definitions={template.parameter_definitions}
                            values={values}
                            errors={fieldErrors}
                            disabled={busy || cloning}
                            onChange={setValue}
                        />

                        <div className="grid gap-2 border-t pt-5 sm:max-w-sm">
                            <Label htmlFor="query-template-tenant">
                                {t('queries.environment')}
                            </Label>
                            <Select
                                value={tenant}
                                onValueChange={setTenant}
                                disabled={busy || cloning}
                            >
                                <SelectTrigger id="query-template-tenant">
                                    <SelectValue
                                        placeholder={t(
                                            'queries.chooseEnvironment',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {tenantKeys.map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {tenants[key]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy || cloning || tenant === ''}
                                onClick={() => void execute('preview')}
                            >
                                {status === 'previewing' ? (
                                    <Spinner />
                                ) : (
                                    <Eye className="size-4" />
                                )}
                                {status === 'previewing'
                                    ? t('templates.previewing')
                                    : t('templates.preview')}
                            </Button>
                            <Button
                                type="button"
                                disabled={busy || cloning || tenant === ''}
                                onClick={() => void execute('run')}
                            >
                                {status === 'running' ? (
                                    <Spinner />
                                ) : (
                                    <Play className="size-4" />
                                )}
                                {status === 'running'
                                    ? t('templates.running')
                                    : t('templates.run')}
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {busy && (
                    <div className="space-y-2">
                        <Skeleton className="h-9 w-full" />
                        <Skeleton className="h-9 w-full" />
                        <Skeleton className="h-9 w-full" />
                    </div>
                )}

                {status === 'done' && errors.length > 0 && (
                    <AlertError
                        title={t('templates.executionError')}
                        errors={errors}
                    />
                )}

                {status === 'done' && errors.length === 0 && result && (
                    <QueryResultView
                        result={result}
                        tenantLabel={tenantLabel}
                    />
                )}
            </div>
        </>
    );
}

ShowQueryTemplate.layout = {
    breadcrumbs: [
        { title: 'Modèles prédéfinis', href: queryTemplates.index() },
        { title: 'Configurer', href: '#' },
    ],
};
