import { AlertCircle, Save, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { ResultsTable } from '@/components/queries/results-table';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

const DEFAULT_LIMIT = 25;
const PREVIEW_DELAY_MS = 500;

export type ResourceSuggestion = {
    key: string;
    label: string;
    description: string;
    domain: string;
    method: string;
    path: string;
    keywords: string[];
    preview_fields: string[];
};

export type QueryFormDefaults = {
    name?: string;
    description?: string | null;
    resource_path?: string;
    tenant_key?: string | null;
    parameters?: {
        limit?: number | null;
        q?: string | null;
        fields?: string | null;
    } | null;
    visibility?: 'private' | 'shared';
};

type PreviewResult = {
    tenant: string;
    resource: ResourceSuggestion | null;
    items: Record<string, unknown>[];
    count: number;
    hasMore: boolean;
    error: string | null;
};

type QueryFormProps = {
    errors: Partial<Record<string, string>>;
    processing: boolean;
    submitLabel: string;
    defaults?: QueryFormDefaults;
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
};

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

function resolveResource(
    intent: string,
    suggestions: ResourceSuggestion[],
): ResourceSuggestion | null {
    const normalizedIntent = normalize(intent);

    if (normalizedIntent === '') {
        return null;
    }

    return (
        suggestions.find((suggestion) =>
            suggestion.keywords.some((keyword) =>
                normalizedIntent.includes(normalize(keyword)),
            ),
        ) ?? null
    );
}

function parseLimit(value: string): number {
    const parsed = Number.parseInt(value, 10);

    if (Number.isNaN(parsed)) {
        return DEFAULT_LIMIT;
    }

    return Math.min(500, Math.max(1, parsed));
}

function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function readPreviewError(data: unknown): string {
    if (isRecord(data)) {
        if (typeof data.message === 'string') {
            return data.message;
        }

        if (typeof data.error === 'string') {
            return data.error;
        }
    }

    return "Impossible de préparer l'aperçu pour le moment.";
}

function PreviewSkeleton() {
    return (
        <div className="flex flex-col gap-2">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
        </div>
    );
}

function EmptyPreview({ message }: { message: string }) {
    return (
        <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
            {message}
        </div>
    );
}

function QueryPreview({
    resource,
    status,
    result,
    error,
    tenantLabel,
    limit,
}: {
    resource: ResourceSuggestion | null;
    status: 'idle' | 'loading' | 'done';
    result: PreviewResult | null;
    error: string | null;
    tenantLabel: string;
    limit: number;
}) {
    const previewError = error ?? result?.error ?? null;

    return (
        <Card className="rounded-lg">
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <CardTitle className="text-base">
                            Prévisualisation
                        </CardTitle>
                        <CardDescription>
                            {resource
                                ? `${resource.method} ${resource.path}`
                                : "Aucune ressource Oracle n'est sélectionnée."}
                        </CardDescription>
                    </div>

                    {resource && (
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="secondary">{resource.domain}</Badge>
                            <Badge variant="outline">{limit} lignes</Badge>
                            <Badge variant="outline">{tenantLabel}</Badge>
                        </div>
                    )}
                </div>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {!resource && (
                    <Alert>
                        <Search />
                        <AlertTitle>Demande à préciser</AlertTitle>
                        <AlertDescription>
                            Exemple: liste des fournisseurs.
                        </AlertDescription>
                    </Alert>
                )}

                {resource && status === 'loading' && <PreviewSkeleton />}

                {resource && status !== 'loading' && previewError && (
                    <Alert variant="destructive">
                        <AlertCircle />
                        <AlertTitle>Aperçu impossible</AlertTitle>
                        <AlertDescription>{previewError}</AlertDescription>
                    </Alert>
                )}

                {resource &&
                    status === 'done' &&
                    !previewError &&
                    result?.items.length === 0 && (
                        <EmptyPreview message="Aucune ligne retournée par Oracle." />
                    )}

                {resource &&
                    status === 'done' &&
                    !previewError &&
                    result &&
                    result.items.length > 0 && (
                        <div className="flex flex-col gap-3">
                            <p className="text-sm text-muted-foreground">
                                {result.count} résultat(s)
                                {result.hasMore &&
                                    ' (plus de résultats disponibles)'}
                            </p>
                            <ResultsTable items={result.items} />
                        </div>
                    )}

                {resource && status === 'idle' && (
                    <EmptyPreview message="L'aperçu Oracle apparaîtra ici." />
                )}
            </CardContent>
        </Card>
    );
}

export function QueryForm({
    errors,
    processing,
    submitLabel,
    defaults,
    resourceSuggestions,
    tenants,
    defaultTenant,
}: QueryFormProps) {
    const parameters = defaults?.parameters ?? undefined;
    const tenantKeys = useMemo(() => Object.keys(tenants), [tenants]);
    const [selectedTenant, setSelectedTenant] = useState(() =>
        tenantKeys.includes(defaultTenant)
            ? defaultTenant
            : (defaults?.tenant_key ?? tenantKeys[0] ?? ''),
    );
    const [intent, setIntent] = useState(defaults?.description ?? '');
    const [limitValue, setLimitValue] = useState(
        String(parameters?.limit ?? DEFAULT_LIMIT),
    );
    const [previewStatus, setPreviewStatus] = useState<
        'idle' | 'loading' | 'done'
    >('idle');
    const [previewResult, setPreviewResult] = useState<PreviewResult | null>(
        null,
    );
    const [previewError, setPreviewError] = useState<{
        resourceKey: string;
        tenant: string;
        message: string;
    } | null>(null);

    const limit = useMemo(() => parseLimit(limitValue), [limitValue]);
    const resource = useMemo(
        () => resolveResource(intent, resourceSuggestions),
        [intent, resourceSuggestions],
    );
    const resourceKey = resource?.key ?? null;
    const activePreviewResult =
        previewResult?.resource?.key === resourceKey &&
        previewResult.tenant === selectedTenant
            ? previewResult
            : null;
    const activePreviewError =
        previewError?.resourceKey === resourceKey &&
        previewError.tenant === selectedTenant
            ? previewError.message
            : null;
    const activePreviewStatus =
        resource === null
            ? 'idle'
            : activePreviewResult ||
                activePreviewError ||
                previewStatus === 'loading'
              ? previewStatus
              : 'idle';
    const previewUrl = queries.preview.url();
    const tenantLabel =
        tenants[activePreviewResult?.tenant ?? selectedTenant] ??
        activePreviewResult?.tenant ??
        selectedTenant;

    useEffect(() => {
        const trimmedIntent = intent.trim();

        if (
            trimmedIntent === '' ||
            resourceKey === null ||
            selectedTenant === ''
        ) {
            return;
        }

        const controller = new AbortController();
        const currentResourceKey = resourceKey;
        const timeoutId = window.setTimeout(async () => {
            setPreviewStatus('loading');
            setPreviewError(null);

            try {
                const response = await fetch(previewUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': readCsrfToken(),
                    },
                    credentials: 'same-origin',
                    signal: controller.signal,
                    body: JSON.stringify({
                        intent: trimmedIntent,
                        tenant: selectedTenant,
                        parameters: { limit },
                    }),
                });
                const data = (await response
                    .json()
                    .catch(() => null)) as PreviewResult | null;

                if (!response.ok) {
                    setPreviewResult(null);
                    setPreviewError({
                        resourceKey: currentResourceKey,
                        tenant: selectedTenant,
                        message: readPreviewError(data),
                    });
                    setPreviewStatus('done');

                    return;
                }

                if (data === null) {
                    setPreviewResult(null);
                    setPreviewError({
                        resourceKey: currentResourceKey,
                        tenant: selectedTenant,
                        message: readPreviewError(data),
                    });
                    setPreviewStatus('done');

                    return;
                }

                setPreviewResult(data);
                setPreviewStatus('done');
            } catch (error) {
                if (isAbortError(error)) {
                    return;
                }

                setPreviewResult(null);
                setPreviewError({
                    resourceKey: currentResourceKey,
                    tenant: selectedTenant,
                    message:
                        'Erreur réseau lors de la prévisualisation Oracle.',
                });
                setPreviewStatus('done');
            }
        }, PREVIEW_DELAY_MS);

        return () => {
            window.clearTimeout(timeoutId);
            controller.abort();
        };
    }, [intent, limit, previewUrl, resourceKey, selectedTenant]);

    const technicalError = errors.resource_path
        ? "Je n'ai pas encore trouvé l'API Oracle pour cette demande."
        : undefined;

    return (
        <div className="flex flex-col gap-6">
            <input name="name" type="hidden" value={resource?.label ?? ''} />
            <input name="description" type="hidden" value={intent.trim()} />
            <input
                name="resource_path"
                type="hidden"
                value={resource?.path ?? ''}
            />
            <input name="tenant_key" type="hidden" value={selectedTenant} />
            <input name="visibility" type="hidden" value="private" />
            <input name="parameters[limit]" type="hidden" value={limit} />

            <div className="flex flex-col gap-2">
                <Label htmlFor="intent">Demande</Label>
                <Textarea
                    id="intent"
                    name="intent"
                    required
                    rows={5}
                    value={intent}
                    onChange={(event) => setIntent(event.target.value)}
                    placeholder="Je veux la liste des fournisseurs"
                    aria-invalid={Boolean(
                        errors.description ?? errors.resource_path,
                    )}
                />
                <InputError message={errors.description ?? technicalError} />
            </div>

            <div className="flex max-w-44 flex-col gap-2">
                <Label htmlFor="parameters-limit">Limite</Label>
                <Input
                    id="parameters-limit"
                    inputMode="numeric"
                    min={1}
                    max={500}
                    type="number"
                    value={limitValue}
                    onBlur={() => setLimitValue(String(limit))}
                    onChange={(event) => setLimitValue(event.target.value)}
                    aria-invalid={Boolean(errors['parameters.limit'])}
                />
                <InputError message={errors['parameters.limit']} />
            </div>

            <div className="flex max-w-xs flex-col gap-2">
                <Label htmlFor="tenant_key">Tenant Oracle</Label>
                <Select
                    value={selectedTenant}
                    onValueChange={setSelectedTenant}
                    disabled={tenantKeys.length === 0}
                >
                    <SelectTrigger
                        id="tenant_key"
                        className="w-full"
                        aria-invalid={Boolean(errors.tenant_key)}
                    >
                        <SelectValue placeholder="Choisir un tenant" />
                    </SelectTrigger>
                    <SelectContent>
                        {tenantKeys.map((key) => (
                            <SelectItem key={key} value={key}>
                                {tenants[key]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.tenant_key} />
            </div>

            <QueryPreview
                resource={resource}
                status={activePreviewStatus}
                result={activePreviewResult}
                error={activePreviewError}
                tenantLabel={tenantLabel}
                limit={limit}
            />

            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    disabled={
                        processing || resource === null || selectedTenant === ''
                    }
                >
                    {processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Save data-icon="inline-start" />
                    )}
                    {submitLabel}
                </Button>
                <p className="text-sm text-muted-foreground">
                    {resource
                        ? `${resource.label} sera enregistrée pour ${tenantLabel}.`
                        : 'Aucune requête prête à enregistrer.'}
                </p>
            </div>
        </div>
    );
}
