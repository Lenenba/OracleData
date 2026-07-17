import { Eye, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { QueryResultView } from '@/components/queries/query-result';
import type { QueryResult } from '@/components/queries/query-result';
import { Button } from '@/components/ui/button';
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

export type JoinKeyDef = {
    local_key: string;
    remote_key: string;
    label: string;
};

export type ResourceSuggestion = {
    key: string;
    label: string;
    description: string;
    domain: string;
    method: string;
    path: string;
    keywords: string[];
    preview_fields: string[];
    fields?: string[];
    child_resources?: string[];
    child_fields?: Record<string, string[]>;
    join_keys?: Record<string, JoinKeyDef>;
};

export type QueryFormDefaults = {
    name?: string;
    description?: string | null;
    tenant_key?: string | null;
    parameters?: { limit?: number | null } | null;
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

function parseLimit(value: string): number {
    const parsed = Number.parseInt(value, 10);

    if (Number.isNaN(parsed)) {
        return DEFAULT_LIMIT;
    }

    return Math.min(500, Math.max(1, parsed));
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function readError(data: unknown): string {
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

export function QueryForm({
    errors,
    processing,
    submitLabel,
    defaults,
    tenants,
    defaultTenant,
}: QueryFormProps) {
    const tenantKeys = useMemo(() => Object.keys(tenants), [tenants]);
    const [selectedTenant, setSelectedTenant] = useState(() =>
        tenantKeys.includes(defaultTenant)
            ? defaultTenant
            : (defaults?.tenant_key ?? tenantKeys[0] ?? ''),
    );
    const [intent, setIntent] = useState(defaults?.description ?? '');
    const [name, setName] = useState(defaults?.name ?? '');
    const [visibility, setVisibility] = useState<'private' | 'shared'>('private');
    const [limitValue, setLimitValue] = useState(
        String(defaults?.parameters?.limit ?? DEFAULT_LIMIT),
    );
    const [status, setStatus] = useState<'idle' | 'loading' | 'done'>('idle');
    const [result, setResult] = useState<QueryResult | null>(null);
    const [fetchError, setFetchError] = useState<string | null>(null);

    const limit = useMemo(() => parseLimit(limitValue), [limitValue]);
    const tenantLabel = tenants[selectedTenant] ?? selectedTenant;

    const isSaveable =
        result !== null &&
        !result.error &&
        !fetchError &&
        (result.mode === 'single'
            ? result.resource !== null
            : result.mode === 'agent');

    const resolvedName =
        name.trim() !== ''
            ? name.trim()
            : (result?.resource?.label ?? intent.trim().slice(0, 80));

    const parameters: Record<string, unknown> =
        result?.mode === 'single' && isRecord(result.parameters)
            ? result.parameters
            : {};

    async function preview() {
        if (intent.trim() === '' || selectedTenant === '') {
            return;
        }

        setStatus('loading');
        setFetchError(null);
        setResult(null);

        try {
            const response = await fetch(queries.preview.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    intent: intent.trim(),
                    tenant: selectedTenant,
                    parameters: { limit },
                }),
            });

            const data = (await response
                .json()
                .catch(() => null)) as QueryResult | null;

            if (!response.ok || data === null) {
                setFetchError(readError(data));
                setStatus('done');

                return;
            }

            setResult(data);

            if (name.trim() === '' && data.resource) {
                setName(data.resource.label);
            }

            setStatus('done');
        } catch {
            setFetchError("Erreur réseau lors de la préparation de l'aperçu.");
            setStatus('done');
        }
    }

    return (
        <div className="flex flex-col gap-6">
            {/* Champs soumis à l'enregistrement, dérivés de l'aperçu résolu. */}
            <input type="hidden" name="name" value={resolvedName} />
            <input type="hidden" name="description" value={intent.trim()} />
            <input type="hidden" name="mode" value={result?.mode ?? 'single'} />
            <input
                type="hidden"
                name="resource_path"
                value={result?.resource?.path ?? ''}
            />
            <input type="hidden" name="tenant_key" value={selectedTenant} />
            <input type="hidden" name="visibility" value="private" />
            {Object.entries(parameters).map(([key, value]) => (
                <input
                    key={key}
                    type="hidden"
                    name={`parameters[${key}]`}
                    value={String(value)}
                />
            ))}

            <div className="flex flex-col gap-2">
                <Label htmlFor="intent">Demande</Label>
                <Textarea
                    id="intent"
                    required
                    rows={4}
                    value={intent}
                    onChange={(event) => setIntent(event.target.value)}
                    placeholder="Ex : liste des fournisseurs avec leur numéro, leurs contacts et leurs sites"
                    aria-invalid={Boolean(
                        errors.description ?? errors.resource_path,
                    )}
                />
                <InputError
                    message={errors.description ?? errors.resource_path}
                />
            </div>

            <div className="flex flex-wrap items-end gap-4">
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

                <div className="flex max-w-xs flex-1 flex-col gap-2">
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

                <Button
                    type="button"
                    variant="secondary"
                    onClick={preview}
                    disabled={
                        status === 'loading' ||
                        intent.trim() === '' ||
                        selectedTenant === ''
                    }
                >
                    {status === 'loading' ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Eye data-icon="inline-start" />
                    )}
                    Prévisualiser
                </Button>
            </div>

            <div className="flex flex-col gap-2">
                <Label htmlFor="name">Nom de la requête</Label>
                <Input
                    id="name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder={result?.resource?.label ?? 'Nom à enregistrer'}
                    aria-invalid={Boolean(errors.name)}
                />
                <InputError message={errors.name} />
            </div>

            <div className="flex flex-col gap-2">
                <Label>Visibilité</Label>
                <div className="flex gap-4">
                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                        <input
                            type="radio"
                            name="_visibility_ui"
                            value="private"
                            checked={visibility === 'private'}
                            onChange={() => setVisibility('private')}
                        />
                        Privée — visible uniquement par moi
                    </label>
                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                        <input
                            type="radio"
                            name="_visibility_ui"
                            value="shared"
                            checked={visibility === 'shared'}
                            onChange={() => setVisibility('shared')}
                        />
                        Partagée — visible par tous
                    </label>
                </div>
            </div>

            {status === 'loading' && (
                <div className="flex flex-col gap-2">
                    <Skeleton className="h-9 w-full" />
                    <Skeleton className="h-9 w-full" />
                    <Skeleton className="h-9 w-full" />
                </div>
            )}

            {fetchError && (
                <QueryResultView
                    result={
                        {
                            mode: 'single',
                            tenant: selectedTenant,
                            resource: null,
                            parameters: null,
                            columns: null,
                            analysis: null,
                            items: [],
                            count: 0,
                            hasMore: false,
                            oracleCalls: [],
                            clarification: null,
                            error: fetchError,
                        } satisfies QueryResult
                    }
                    tenantLabel={tenantLabel}
                />
            )}

            {status === 'done' && !fetchError && result && (
                <QueryResultView result={result} tenantLabel={tenantLabel} />
            )}

            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={processing || !isSaveable}>
                    {processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Save data-icon="inline-start" />
                    )}
                    {submitLabel}
                </Button>
                <p className="text-sm text-muted-foreground">
                    {isSaveable
                        ? `Prête à enregistrer pour ${tenantLabel}.`
                        : "Prévisualisez d'abord pour vérifier le résultat."}
                </p>
            </div>
        </div>
    );
}
