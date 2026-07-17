import { Link, router } from '@inertiajs/react';
import {
    Braces,
    Code2,
    Globe,
    Lock,
    RotateCw,
    Save,
    Server,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AlertError from '@/components/alert-error';
import { QueryConfigPanel } from '@/components/queries/query-config-panel';
import { QueryResultView } from '@/components/queries/query-result';
import { ResourcePicker } from '@/components/queries/resource-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useLivePreview } from '@/hooks/use-live-preview';
import { useI18n } from '@/i18n/i18n-context';
import {
    DEFAULT_LIMIT,
    domainIcon,
    filterRowsToQ,
    generateBipSql,
    isRecord,
    parseLimit,
    qToFilterRows,
} from '@/lib/query-spec';
import type {
    ChildFieldsMap,
    FilterRow,
    ResourceSuggestion,
} from '@/lib/query-spec';
import oracleTenants from '@/routes/oracle-tenants';
import queries from '@/routes/queries';

type BuilderMode = 'create' | 'edit';

type BuilderInitialState = {
    queryId?: number;
    name?: string;
    visibility?: 'private' | 'shared';
    resourceKey?: string;
    tenantKey?: string;
    fields?: string[];
    /** Enfants Oracle imbriqués (child_resources) */
    expand?: string[];
    /** Clés de ressources jointes cross-resource (join_keys) */
    joins?: string[];
    childFields?: ChildFieldsMap;
    filterQ?: string;
    orderBy?: string;
    limit?: number;
};

type QueryBuilderProps = {
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
    mode?: BuilderMode;
    initialState?: BuilderInitialState;
};

/**
 * Query builder live : configuration à gauche, aperçu à droite mis à jour
 * automatiquement à chaque modification (debounce 600 ms, requête en vol
 * annulée). L'enregistrement persiste la spécification canonique renvoyée
 * par le dernier aperçu réussi — on enregistre exactement ce qu'on voit.
 */
export function QueryBuilder({
    resourceSuggestions,
    tenants,
    defaultTenant,
    mode = 'create',
    initialState,
}: QueryBuilderProps) {
    const { t } = useI18n();
    const tenantKeys = Object.keys(tenants);
    const initialResourceKey = initialState?.resourceKey;
    const initialResource = useMemo(
        () =>
            initialResourceKey
                ? (resourceSuggestions.find(
                      (r) => r.key === initialResourceKey,
                  ) ?? null)
                : null,
        [resourceSuggestions, initialResourceKey],
    );

    const [resource, setResource] = useState<ResourceSuggestion | null>(
        initialResource,
    );
    const [fields, setFields] = useState<string[]>(initialState?.fields ?? []);
    const [expand, setExpand] = useState<string[]>(initialState?.expand ?? []);
    const [joins, setJoins] = useState<string[]>(initialState?.joins ?? []);
    const [childFields, setChildFields] = useState<ChildFieldsMap>(
        initialState?.childFields ?? {},
    );
    const [filterRows, setFilterRows] = useState<FilterRow[]>(() =>
        qToFilterRows(
            initialState?.filterQ ?? '',
            initialResource?.fields ?? [],
        ),
    );
    const [orderBy, setOrderBy] = useState(initialState?.orderBy ?? '');
    const [tenant, setTenant] = useState(() => {
        const requestedTenant = initialState?.tenantKey;

        if (requestedTenant && tenantKeys.includes(requestedTenant)) {
            return requestedTenant;
        }

        return tenantKeys.includes(defaultTenant)
            ? defaultTenant
            : (tenantKeys[0] ?? '');
    });
    const [limit, setLimit] = useState(
        String(initialState?.limit ?? DEFAULT_LIMIT),
    );

    const [name, setName] = useState(initialState?.name ?? '');
    const [visibility, setVisibility] = useState<'private' | 'shared'>(
        initialState?.visibility ?? 'private',
    );
    const [saving, setSaving] = useState(false);
    const [activeTab, setActiveTab] = useState<'preview' | 'sql'>('preview');

    const parsedLimit = parseLimit(limit);
    const tenantLabel = tenants[tenant] ?? tenant;
    const filterQ = filterRowsToQ(filterRows, resource?.fields ?? []);

    // Ne transmet que les sélections de champs des enfants/jointures actifs
    const activeChildFields = useMemo(() => {
        const map: ChildFieldsMap = {};

        for (const key of [...expand, ...joins]) {
            if ((childFields[key] ?? []).length > 0) {
                map[key] = childFields[key];
            }
        }

        return map;
    }, [expand, joins, childFields]);

    const live = useLivePreview({
        resourceKey: resource?.key ?? null,
        tenant,
        fields,
        expand,
        joins,
        childFields: activeChildFields,
        filterQ,
        orderBy,
        limit: parsedLimit,
    });

    const isSaveable =
        live.isCurrent &&
        live.result !== null &&
        live.result.mode === 'single' &&
        live.result.resource !== null;

    const resolvedName = name.trim() || resource?.label || '';

    const description = useMemo(() => {
        if (!resource) {
            return '';
        }

        const parts: string[] = [resource.label];

        if (expand.length > 0) {
            parts.push(`avec ${expand.join(', ')}`);
        }

        if (joins.length > 0) {
            parts.push(`joint à ${joins.join(', ')}`);
        }

        if (filterQ.trim()) {
            parts.push(`filtré : ${filterQ.trim()}`);
        }

        if (orderBy.trim()) {
            parts.push(`trié par ${orderBy.trim()}`);
        }

        return parts.join(' — ');
    }, [resource, expand, joins, filterQ, orderBy]);

    const bipSql = useMemo(
        () =>
            resource
                ? generateBipSql(
                      resource,
                      fields,
                      expand,
                      joins,
                      childFields,
                      filterQ,
                      orderBy,
                      parsedLimit,
                  )
                : '',
        [
            resource,
            fields,
            expand,
            joins,
            childFields,
            filterQ,
            orderBy,
            parsedLimit,
        ],
    );

    function resetConfig() {
        setFields([]);
        setExpand([]);
        setJoins([]);
        setChildFields({});
        setFilterRows([]);
        setOrderBy('');
    }

    function selectResource(r: ResourceSuggestion) {
        if (resource?.key !== r.key) {
            resetConfig();
        }

        setResource(r);
    }

    function changeResource() {
        resetConfig();
        setResource(null);
    }

    function save() {
        if (!isSaveable || !live.result || !resource || saving) {
            return;
        }

        setSaving(true);

        const baseParams: Record<string, string | number | boolean | null> =
            isRecord(live.result.parameters)
                ? (live.result.parameters as Record<
                      string,
                      string | number | boolean | null
                  >)
                : {};

        // resource_key permet de pré-remplir le builder à la prochaine édition
        const parameters = { ...baseParams, resource_key: resource.key };

        const payload = {
            name: resolvedName,
            description,
            mode: 'single',
            resource_path: live.result.resource?.path ?? '',
            tenant_key: tenant,
            visibility,
            parameters,
        };

        if (mode === 'edit' && initialState?.queryId !== undefined) {
            router.put(queries.update.url(initialState.queryId), payload, {
                onError: () => setSaving(false),
            });
        } else {
            router.post(queries.store.url(), payload, {
                onError: () => setSaving(false),
            });
        }
    }

    if (tenantKeys.length === 0) {
        return (
            <div className="rounded-xl border border-dashed bg-card px-6 py-12 text-center">
                <Server className="mx-auto size-10 text-muted-foreground" />
                <h3 className="mt-4 font-semibold">
                    {t('queries.noConnectionsTitle')}
                </h3>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    {t('queries.noConnectionsDescription')}
                </p>
                <Button asChild className="mt-5">
                    <Link href={oracleTenants.index()}>
                        {t('queries.addConnection')}
                    </Link>
                </Button>
            </div>
        );
    }

    if (!resource) {
        return (
            <ResourcePicker
                resources={resourceSuggestions}
                selected={resource}
                onSelect={selectResource}
            />
        );
    }

    return (
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            {/* ─ Colonne gauche : configuration ─ */}
            <QueryConfigPanel
                resource={resource}
                allResources={resourceSuggestions}
                tenants={tenants}
                fields={fields}
                setFields={setFields}
                expand={expand}
                setExpand={setExpand}
                joins={joins}
                setJoins={setJoins}
                childFields={childFields}
                setChildFields={setChildFields}
                filterRows={filterRows}
                setFilterRows={setFilterRows}
                orderBy={orderBy}
                setOrderBy={setOrderBy}
                tenant={tenant}
                setTenant={setTenant}
                limit={limit}
                setLimit={setLimit}
                onChangeResource={changeResource}
            />

            {/* ─ Colonne droite : aperçu live + enregistrement ─ */}
            <div className="flex flex-col gap-4 lg:sticky lg:top-4">
                <div className="overflow-hidden rounded-xl border">
                    {/* Onglets + statut */}
                    <div className="flex items-center border-b bg-muted/30">
                        <button
                            type="button"
                            onClick={() => setActiveTab('preview')}
                            className={[
                                'flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'preview'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            <Braces className="size-4" />
                            Aperçu live
                            {live.result !== null && (
                                <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs font-semibold">
                                    {live.result.count}
                                </span>
                            )}
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('sql')}
                            className={[
                                'flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'sql'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            <Code2 className="size-4" />
                            SQL BIP
                        </button>

                        <div className="ml-auto flex items-center gap-2 pr-3">
                            {live.loading && (
                                <Spinner className="size-4 text-muted-foreground" />
                            )}
                            <button
                                type="button"
                                onClick={live.refresh}
                                disabled={live.loading}
                                title="Rafraîchir l'aperçu"
                                className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                            >
                                <RotateCw className="size-3.5" />
                            </button>
                        </div>
                    </div>

                    {activeTab === 'preview' && (
                        <div className="flex flex-col gap-3 p-3">
                            {live.error && (
                                <AlertError
                                    title="La requête a échoué"
                                    errors={[live.error]}
                                />
                            )}

                            {live.result !== null ? (
                                <div
                                    className={
                                        live.loading
                                            ? 'pointer-events-none opacity-50 transition-opacity'
                                            : 'transition-opacity'
                                    }
                                >
                                    <QueryResultView
                                        result={live.result}
                                        tenantLabel={tenantLabel}
                                    />
                                </div>
                            ) : live.loading ? (
                                <div className="space-y-2 py-2">
                                    <Skeleton className="h-8 w-full" />
                                    <Skeleton className="h-8 w-full" />
                                    <Skeleton className="h-8 w-3/4" />
                                </div>
                            ) : !live.error ? (
                                <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                                    L'aperçu apparaîtra ici dès que la
                                    configuration est prête.
                                </div>
                            ) : null}
                        </div>
                    )}

                    {activeTab === 'sql' && (
                        <div className="p-4">
                            <div className="mb-3 flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium">
                                        SQL généré pour Oracle BI Publisher
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Copiez ce SQL dans votre rapport BIP.
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        void navigator.clipboard.writeText(
                                            bipSql,
                                        )
                                    }
                                >
                                    Copier
                                </Button>
                            </div>
                            <pre className="overflow-x-auto rounded-lg border bg-muted p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-foreground">
                                {bipSql}
                            </pre>
                        </div>
                    )}
                </div>

                {/* ─ Enregistrement ─ */}
                <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex min-w-48 flex-1 flex-col gap-1.5">
                            <Label
                                htmlFor="qb-name"
                                className="text-xs font-medium"
                            >
                                Nom de la requête
                            </Label>
                            <Input
                                id="qb-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={resource.label}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label className="text-xs font-medium">
                                Visibilité
                            </Label>
                            <div className="flex gap-2">
                                {(['private', 'shared'] as const).map((v) => (
                                    <button
                                        key={v}
                                        type="button"
                                        onClick={() => setVisibility(v)}
                                        className={[
                                            'flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm transition-colors',
                                            visibility === v
                                                ? 'border-primary bg-primary/5 font-medium text-primary'
                                                : 'border-border text-muted-foreground hover:border-primary/40',
                                        ].join(' ')}
                                    >
                                        {v === 'private' ? (
                                            <>
                                                <Lock className="size-3.5" />{' '}
                                                Privée
                                            </>
                                        ) : (
                                            <>
                                                <Globe className="size-3.5" />{' '}
                                                Partagée
                                            </>
                                        )}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center justify-between gap-3">
                        {isSaveable ? (
                            <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                ✓ {live.result?.count ?? 0} résultat(s) — prêt à
                                enregistrer
                            </span>
                        ) : (
                            <Badge
                                variant="secondary"
                                className="text-[11px] font-normal text-muted-foreground"
                            >
                                {domainIcon(resource.domain)}{' '}
                                {live.loading
                                    ? 'Aperçu en cours…'
                                    : "L'enregistrement s'active dès qu'un aperçu réussit."}
                            </Badge>
                        )}

                        <Button onClick={save} disabled={!isSaveable || saving}>
                            {saving ? (
                                <>
                                    <Spinner data-icon="inline-start" />{' '}
                                    Enregistrement…
                                </>
                            ) : (
                                <>
                                    <Save data-icon="inline-start" />
                                    {mode === 'edit'
                                        ? 'Mettre à jour'
                                        : 'Enregistrer la requête'}
                                </>
                            )}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
