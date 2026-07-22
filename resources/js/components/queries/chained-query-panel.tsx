import {
    Link2,
    Play,
    Plus,
    Trash2,
    ChevronDown,
    ChevronUp,
    LoaderCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    index as indexChains,
    store as storeChain,
    destroy as destroyChain,
    run as runChain,
} from '@/actions/App/Http/Controllers/QueryChainController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';

type ChainItem = {
    id: number;
    secondary_query_id: number;
    secondary_name: string | null;
    extraction_field: string;
    injection_param: string;
    injection_operator: 'equals' | 'in';
    label: string | null;
    position: number;
};

type ChainResult = {
    chain_id: number;
    extraction_field: string;
    injection_param: string;
    filter_q: string;
    injected_values: Array<string | number>;
    items: Array<Record<string, unknown>>;
    count: number;
    hasMore: boolean;
    error?: string;
};

type AccessibleQuery = {
    id: number;
    name: string;
};

type Props = {
    queryId: number;
    /** Items from the last successful primary run — needed to trigger chain execution. */
    primaryItems: Array<Record<string, unknown>>;
    /** Current tenant key selected by the user. */
    tenant: string;
    tenants: Record<string, string>;
    /** Whether the current user can manage chains (is owner). */
    canManage: boolean;
    /** Queries accessible to this user (for the secondary picker). */
    accessibleQueries: AccessibleQuery[];
};

type AddFormState = {
    secondary_query_id: string;
    extraction_field: string;
    injection_param: string;
    injection_operator: 'equals' | 'in';
    label: string;
};

const DEFAULT_FORM: AddFormState = {
    secondary_query_id: '',
    extraction_field: '',
    injection_param: '',
    injection_operator: 'in',
    label: '',
};

/**
 * Lot chaining — Panel affiché sous les résultats primaires.
 *
 * Permet à l'utilisateur propriétaire de :
 *  - configurer des chaînes (champ extrait → filtre secondaire)
 *  - lancer l'exécution chaînée après un run primaire réussi
 *  - voir les résultats secondaires inline
 */
export function ChainedQueryPanel({
    queryId,
    primaryItems,
    tenant,
    canManage,
    accessibleQueries,
}: Props) {
    const { t } = useI18n();
    const [chains, setChains] = useState<ChainItem[]>([]);
    const [loadingChains, setLoadingChains] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [form, setForm] = useState<AddFormState>(DEFAULT_FORM);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [results, setResults] = useState<
        Record<number, ChainResult & { status: 'idle' | 'loading' | 'done' }>
    >({});
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});

    useEffect(() => {
        let cancelled = false;

        fetch(indexChains.url(queryId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : []))
            .then((data: unknown) => {
                if (!cancelled && Array.isArray(data)) {
                    setChains(data as ChainItem[]);
                }
            })
            .catch(() => {})
            .finally(() => {
                if (!cancelled) {
                    setLoadingChains(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [queryId]);

    function toggleExpand(chainId: number) {
        setExpanded((prev) => ({ ...prev, [chainId]: !prev[chainId] }));
    }

    async function handleAddChain() {
        if (
            form.secondary_query_id === '' ||
            form.extraction_field.trim() === '' ||
            form.injection_param.trim() === ''
        ) {
            return;
        }

        setSaving(true);
        setSaveError(null);

        try {
            const res = await fetch(storeChain.url(queryId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    secondary_query_id: Number(form.secondary_query_id),
                    extraction_field: form.extraction_field.trim(),
                    injection_param: form.injection_param.trim(),
                    injection_operator: form.injection_operator,
                    label: form.label.trim() || null,
                }),
            });

            const data = (await res.json()) as Record<string, unknown>;

            if (!res.ok) {
                const msg = Object.values(data as Record<string, string[]>)
                    .flat()
                    .find((m) => typeof m === 'string');
                setSaveError(msg ?? t('chains.saveError'));

                return;
            }

            const newChain: ChainItem = {
                id: data.id as number,
                secondary_query_id: data.secondary_query_id as number,
                secondary_name:
                    accessibleQueries.find(
                        (q) => q.id === Number(form.secondary_query_id),
                    )?.name ?? null,
                extraction_field: data.extraction_field as string,
                injection_param: data.injection_param as string,
                injection_operator: data.injection_operator as 'equals' | 'in',
                label: data.label as string | null,
                position: data.position as number,
            };

            setChains((prev) => [...prev, newChain]);
            setForm(DEFAULT_FORM);
            setShowForm(false);
        } catch {
            setSaveError(t('chains.saveError'));
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete(chain: ChainItem) {
        try {
            await fetch(destroyChain.url({ query: queryId, chain: chain.id }), {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
            });
            setChains((prev) => prev.filter((c) => c.id !== chain.id));
            setResults((prev) => {
                const next = { ...prev };
                delete next[chain.id];

                return next;
            });
        } catch {
            // silent
        }
    }

    async function handleRunChain(chain: ChainItem) {
        if (primaryItems.length === 0) {
            return;
        }

        setResults((prev) => ({
            ...prev,
            [chain.id]: {
                ...prev[chain.id],
                status: 'loading',
                items: [],
                count: 0,
                hasMore: false,
                injected_values: [],
                filter_q: '',
                chain_id: chain.id,
                extraction_field: chain.extraction_field,
                injection_param: chain.injection_param,
            },
        }));
        setExpanded((prev) => ({ ...prev, [chain.id]: true }));

        try {
            const res = await fetch(
                runChain.url({ query: queryId, chain: chain.id }),
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': readCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        primary_items: primaryItems,
                        tenant,
                    }),
                },
            );

            const data = (await res.json()) as ChainResult;

            setResults((prev) => ({
                ...prev,
                [chain.id]: {
                    ...data,
                    status: 'done',
                    error: !res.ok
                        ? (data.error ?? t('chains.runError'))
                        : undefined,
                },
            }));
        } catch {
            setResults((prev) => ({
                ...prev,
                [chain.id]: {
                    ...(prev[chain.id] ?? {}),
                    status: 'done',
                    error: t('chains.runError'),
                    items: [],
                    count: 0,
                    hasMore: false,
                    injected_values: [],
                    filter_q: '',
                    chain_id: chain.id,
                    extraction_field: chain.extraction_field,
                    injection_param: chain.injection_param,
                },
            }));
        }
    }

    if (loadingChains) {
        return null;
    }

    if (!canManage && chains.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3">
            {/* Header */}
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <Link2 className="size-4 text-muted-foreground" />
                    {t('chains.title')}
                    {chains.length > 0 && (
                        <Badge variant="secondary">{chains.length}</Badge>
                    )}
                </div>
                {canManage && !showForm && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setShowForm(true)}
                    >
                        <Plus className="size-3.5" />
                        {t('chains.add')}
                    </Button>
                )}
            </div>

            {/* Add form */}
            {canManage && showForm && (
                <div className="space-y-3 rounded-xl border bg-muted/20 p-4">
                    <p className="text-sm font-medium">
                        {t('chains.addTitle')}
                    </p>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs">
                                {t('chains.secondaryQuery')}
                            </Label>
                            <Select
                                value={form.secondary_query_id}
                                onValueChange={(v) =>
                                    setForm((f) => ({
                                        ...f,
                                        secondary_query_id: v,
                                    }))
                                }
                            >
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue
                                        placeholder={t(
                                            'chains.secondaryQueryPlaceholder',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {accessibleQueries
                                        .filter((q) => q.id !== queryId)
                                        .map((q) => (
                                            <SelectItem
                                                key={q.id}
                                                value={String(q.id)}
                                            >
                                                {q.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs">
                                {t('chains.label')}
                            </Label>
                            <Input
                                value={form.label}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        label: e.target.value,
                                    }))
                                }
                                placeholder={t('chains.labelPlaceholder')}
                                className="h-8 text-xs"
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs">
                                {t('chains.extractionField')}
                            </Label>
                            <Input
                                value={form.extraction_field}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        extraction_field: e.target.value,
                                    }))
                                }
                                placeholder="PersonId"
                                className="h-8 font-mono text-xs"
                            />
                            <p className="text-[11px] text-muted-foreground">
                                {t('chains.extractionFieldHint')}
                            </p>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs">
                                {t('chains.injectionParam')}
                            </Label>
                            <Input
                                value={form.injection_param}
                                onChange={(e) =>
                                    setForm((f) => ({
                                        ...f,
                                        injection_param: e.target.value,
                                    }))
                                }
                                placeholder="PersonId"
                                className="h-8 font-mono text-xs"
                            />
                            <p className="text-[11px] text-muted-foreground">
                                {t('chains.injectionParamHint')}
                            </p>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs">
                                {t('chains.operator')}
                            </Label>
                            <Select
                                value={form.injection_operator}
                                onValueChange={(v) =>
                                    setForm((f) => ({
                                        ...f,
                                        injection_operator: v as
                                            | 'equals'
                                            | 'in',
                                    }))
                                }
                            >
                                <SelectTrigger className="h-8 text-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="in">
                                        {t('chains.operatorIn')}
                                    </SelectItem>
                                    <SelectItem value="equals">
                                        {t('chains.operatorEquals')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {saveError && (
                        <Alert variant="destructive">
                            <AlertDescription>{saveError}</AlertDescription>
                        </Alert>
                    )}

                    <div className="flex gap-2">
                        <Button
                            type="button"
                            size="sm"
                            disabled={saving}
                            onClick={() => void handleAddChain()}
                        >
                            {saving ? (
                                <LoaderCircle className="size-3.5 animate-spin" />
                            ) : (
                                <Plus className="size-3.5" />
                            )}
                            {t('chains.save')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={saving}
                            onClick={() => {
                                setShowForm(false);
                                setSaveError(null);
                                setForm(DEFAULT_FORM);
                            }}
                        >
                            {t('common.cancel')}
                        </Button>
                    </div>
                </div>
            )}

            {/* Chain list */}
            {chains.length === 0 && canManage && !showForm && (
                <p className="text-xs text-muted-foreground">
                    {t('chains.empty')}
                </p>
            )}

            <div className="space-y-2">
                {chains.map((chain) => {
                    const res = results[chain.id];
                    const isExpanded = expanded[chain.id] ?? false;
                    const hasPrimary = primaryItems.length > 0;

                    return (
                        <div key={chain.id} className="card">
                            {/* Chain header row */}
                            <div className="flex items-center gap-3 p-3">
                                <Link2 className="size-4 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {chain.label ??
                                            chain.secondary_name ??
                                            t('chains.unnamedChain')}
                                    </p>
                                    <p className="font-mono text-xs text-muted-foreground">
                                        {chain.extraction_field}
                                        {' → '}
                                        {chain.secondary_name ??
                                            `#${chain.secondary_query_id}`}{' '}
                                        <span className="opacity-60">
                                            (
                                            {chain.injection_operator === 'in'
                                                ? 'IN'
                                                : '='}{' '}
                                            {chain.injection_param})
                                        </span>
                                    </p>
                                </div>

                                <div className="flex items-center gap-1">
                                    {res?.status === 'done' && !res.error && (
                                        <Badge
                                            variant="secondary"
                                            className="text-xs"
                                        >
                                            {res.count} {t('chains.rows')}
                                        </Badge>
                                    )}

                                    {hasPrimary && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                res?.status === 'loading' ||
                                                tenant === ''
                                            }
                                            onClick={() =>
                                                void handleRunChain(chain)
                                            }
                                            title={t('chains.runTooltip')}
                                        >
                                            {res?.status === 'loading' ? (
                                                <LoaderCircle className="size-3.5 animate-spin" />
                                            ) : (
                                                <Play className="size-3.5" />
                                            )}
                                            {t('chains.run')}
                                        </Button>
                                    )}

                                    {res?.status === 'done' && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                            onClick={() =>
                                                toggleExpand(chain.id)
                                            }
                                        >
                                            {isExpanded ? (
                                                <ChevronUp className="size-3.5" />
                                            ) : (
                                                <ChevronDown className="size-3.5" />
                                            )}
                                        </Button>
                                    )}

                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-7 text-destructive/60 hover:text-destructive"
                                            onClick={() =>
                                                void handleDelete(chain)
                                            }
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {/* Chain result (expanded) */}
                            {res?.status === 'done' && isExpanded && (
                                <div className="border-t p-3">
                                    {res.error ? (
                                        <Alert variant="destructive">
                                            <AlertDescription>
                                                {res.error}
                                            </AlertDescription>
                                        </Alert>
                                    ) : res.items.length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            {t('chains.noResults', {
                                                field: res.injection_param,
                                                values: res.injected_values.join(
                                                    ', ',
                                                ),
                                            })}
                                        </p>
                                    ) : (
                                        <div className="space-y-2">
                                            <p className="text-xs text-muted-foreground">
                                                {t('chains.filterApplied', {
                                                    q: res.filter_q,
                                                })}
                                            </p>
                                            <div className="overflow-auto rounded border">
                                                <table className="w-full text-xs">
                                                    <thead className="bg-muted/40">
                                                        <tr>
                                                            {Object.keys(
                                                                res.items[0] ??
                                                                    {},
                                                            )
                                                                .slice(0, 10)
                                                                .map((col) => (
                                                                    <th
                                                                        key={
                                                                            col
                                                                        }
                                                                        className="border-b px-2 py-1.5 text-left font-medium text-muted-foreground"
                                                                    >
                                                                        {col}
                                                                    </th>
                                                                ))}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {res.items
                                                            .slice(0, 25)
                                                            .map((row, i) => (
                                                                <tr
                                                                    key={i}
                                                                    className="border-b last:border-0 hover:bg-muted/20"
                                                                >
                                                                    {Object.keys(
                                                                        res
                                                                            .items[0] ??
                                                                            {},
                                                                    )
                                                                        .slice(
                                                                            0,
                                                                            10,
                                                                        )
                                                                        .map(
                                                                            (
                                                                                col,
                                                                            ) => (
                                                                                <td
                                                                                    key={
                                                                                        col
                                                                                    }
                                                                                    className="max-w-[200px] truncate px-2 py-1.5"
                                                                                >
                                                                                    {String(
                                                                                        row[
                                                                                            col
                                                                                        ] ??
                                                                                            '',
                                                                                    )}
                                                                                </td>
                                                                            ),
                                                                        )}
                                                                </tr>
                                                            ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                            {res.hasMore && (
                                                <p className="text-xs text-muted-foreground">
                                                    {t('chains.hasMore')}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
