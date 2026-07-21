import { Link, router } from '@inertiajs/react';
import {
    Bot,
    Braces,
    Code2,
    Eye,
    FolderOpen,
    Globe,
    Lock,
    RotateCw,
    Save,
    Server,
    Share2,
    Tag,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AlertError from '@/components/alert-error';
import { CopilotSuggestionPanel } from '@/components/queries/copilot-suggestion-panel';
import { QueryAccessLevelBadge } from '@/components/queries/query-access-level-badge';
import { QueryConfigPanel } from '@/components/queries/query-config-panel';
import { QueryResultView } from '@/components/queries/query-result';
import { ResourcePicker } from '@/components/queries/resource-picker';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAgentPreview } from '@/hooks/use-agent-preview';
import { useLivePreview } from '@/hooks/use-live-preview';
import { useI18n } from '@/i18n/i18n-context';
import {
    DEFAULT_LIMIT,
    domainIcon,
    filterRowsToQ,
    generateBipSql,
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
import type { QueryAccessLevel } from '@/types/query-sharing';

type BuilderMode = 'create' | 'edit';

type BuilderInitialState = {
    queryId?: number;
    name?: string;
    description?: string | null;
    accessLevel?: QueryAccessLevel;
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
    categoryId?: number | null;
    tags?: string[];
};

export type QueryCategoryOption = {
    id: number;
    slug: string;
    name: string;
    color: string | null;
};

export type QueryTagOption = {
    id: number;
    slug: string;
    name: string;
    label: string;
};

type QueryBuilderProps = {
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
    categories?: QueryCategoryOption[];
    tagSuggestions?: QueryTagOption[];
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
    categories = [],
    tagSuggestions = [],
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
        qToFilterRows(initialState?.filterQ ?? ''),
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
    const [queryDescription, setQueryDescription] = useState(
        initialState?.description ?? '',
    );
    const [accessLevel, setAccessLevel] = useState<QueryAccessLevel>(
        initialState?.accessLevel ?? 'private',
    );
    const accessLevelDescriptions: Record<QueryAccessLevel, string> = {
        private: t('queries.accessLevelPrivateDescription'),
        restricted: t('queries.accessLevelRestrictedDescription'),
        organization: t('queries.accessLevelOrganizationDescription'),
    };
    const [categoryId, setCategoryId] = useState(
        initialState?.categoryId ? String(initialState.categoryId) : '',
    );
    const [tags, setTags] = useState<string[]>(initialState?.tags ?? []);
    const [tagInput, setTagInput] = useState('');
    const [saving, setSaving] = useState(false);
    const [saveErrors, setSaveErrors] = useState<string[]>([]);
    const [activeTab, setActiveTab] = useState<'preview' | 'sql' | 'agent'>('preview');
    const agentPreview = useAgentPreview();
    // Aucun appel Oracle tant que l'utilisateur n'a pas demandé l'aperçu. En
    // édition, la requête existante s'affiche d'emblée (un appel attendu).
    const [previewEnabled, setPreviewEnabled] = useState(mode === 'edit');

    const parsedLimit = parseLimit(limit);
    const tenantLabel = tenants[tenant] ?? tenant;
    const filterQ = filterRowsToQ(filterRows);

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

    const live = useLivePreview(
        {
            resourceKey: resource?.key ?? null,
            tenant,
            expand,
            joins,
            filterQ,
            orderBy,
            limit: parsedLimit,
        },
        previewEnabled,
    );

    // Premier aperçu à la demande ; ensuite les changements de données se
    // rafraîchissent seuls. Sert aussi de bouton « rafraîchir » une fois activé.
    function triggerPreview() {
        setPreviewEnabled(true);
        live.refresh();
    }

    // Projection appliquée côté client à l'aperçu (aucun rappel Oracle) : sans
    // sélection, toutes les colonnes ; sinon les champs choisis plus les enfants
    // et jointures imbriqués, comme le fait le serveur à l'exécution.
    const previewColumns = useMemo<string[] | undefined>(
        () =>
            fields.length === 0
                ? undefined
                : Array.from(new Set([...fields, ...expand, ...joins])),
        [fields, expand, joins],
    );

    const isSaveable =
        live.isCurrent &&
        live.result !== null &&
        live.result.mode === 'single' &&
        live.result.resource !== null;

    const resolvedName = name.trim() || resource?.label || '';

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
                : null,
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
        setSaveErrors([]);

        // Spécification construite à partir de l'état du builder — source de
        // vérité de ce que l'utilisateur a sélectionné — et non de l'aperçu,
        // qui charge désormais toutes les colonnes puis projette côté client.
        const parameters: Record<
            string,
            string | number | string[] | ChildFieldsMap
        > = {
            resource_key: resource.key,
            limit: parsedLimit,
        };

        if (fields.length > 0) {
            parameters.fields = fields.join(',');
        }

        if (expand.length > 0) {
            parameters.expand = expand.join(',');
        }

        if (joins.length > 0) {
            parameters.joins = joins.join(',');
        }

        if (Object.keys(activeChildFields).length > 0) {
            parameters.child_fields = activeChildFields;
        }

        if (filterQ.trim()) {
            parameters.q = filterQ.trim();
        }

        if (orderBy.trim()) {
            parameters.orderBy = orderBy.trim();
        }

        const payload = {
            name: resolvedName,
            description: queryDescription.trim() || null,
            mode: 'single',
            resource_path: live.result.resource?.path ?? '',
            tenant_key: tenant,
            ...(mode === 'create' ? { access_level: accessLevel } : {}),
            category_id: categoryId === '' ? null : Number(categoryId),
            tags,
            parameters,
        };

        const visitOptions = {
            onError: (errors: Record<string, string>) => {
                setSaveErrors(Object.values(errors));
            },
            onFinish: () => setSaving(false),
        };

        if (mode === 'edit' && initialState?.queryId !== undefined) {
            router.put(
                queries.update.url(initialState.queryId),
                payload,
                visitOptions,
            );
        } else {
            router.post(queries.store.url(), payload, visitOptions);
        }
    }

    function addTag(rawValue = tagInput) {
        const value = rawValue.trim().replace(/,$/, '');

        if (value === '' || tags.length >= 10) {
            setTagInput('');

            return;
        }

        const suggestion = tagSuggestions.find(
            (tag) =>
                tag.name.localeCompare(value, undefined, {
                    sensitivity: 'accent',
                }) === 0 ||
                tag.label.localeCompare(value, undefined, {
                    sensitivity: 'accent',
                }) === 0,
        );
        const name = suggestion?.name ?? value;

        setTags((current) =>
            current.some(
                (tag) => tag.toLocaleLowerCase() === name.toLocaleLowerCase(),
            )
                ? current
                : [...current, name],
        );
        setTagInput('');
    }

    function removeTag(name: string) {
        setTags((current) => current.filter((tag) => tag !== name));
    }

    function tagLabel(name: string) {
        return (
            tagSuggestions.find((suggestion) => suggestion.name === name)
                ?.label ?? name
        );
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
                        {/* Lot 11E — agent preview tab */}
                        <button
                            type="button"
                            onClick={() => setActiveTab('agent')}
                            className={[
                                'flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'agent'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ].join(' ')}
                        >
                            <Bot className="size-4" />
                            {t('agentPreview.title')}
                        </button>

                        <div className="ml-auto flex items-center gap-2 pr-3">
                            {live.loading && (
                                <Spinner className="size-4 text-muted-foreground" />
                            )}
                            {previewEnabled && (
                                <button
                                    type="button"
                                    onClick={triggerPreview}
                                    disabled={live.loading}
                                    title="Rafraîchir l'aperçu"
                                    className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                                >
                                    <RotateCw className="size-3.5" />
                                </button>
                            )}
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
                                        columnsOverride={previewColumns}
                                        childColumnsOverride={activeChildFields}
                                    />
                                </div>
                            ) : live.loading ? (
                                <div className="space-y-2 py-2">
                                    <Skeleton className="h-8 w-full" />
                                    <Skeleton className="h-8 w-full" />
                                    <Skeleton className="h-8 w-3/4" />
                                </div>
                            ) : (
                                <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                                    <p className="text-sm text-muted-foreground">
                                        {previewEnabled
                                            ? 'Ajustez la configuration puis relancez l’aperçu.'
                                            : 'Configurez votre requête, puis chargez un aperçu depuis Oracle.'}
                                    </p>
                                    <Button onClick={triggerPreview}>
                                        <Eye data-icon="inline-start" />
                                        Visualiser
                                    </Button>
                                    <p className="max-w-sm text-xs text-muted-foreground">
                                        Une fois chargé, colonnes, tri et
                                        recherche s’ajustent sans rappeler
                                        Oracle.
                                    </p>
                                </div>
                            )}
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
                                    disabled={bipSql === null}
                                    onClick={() => {
                                        if (bipSql !== null) {
                                            void navigator.clipboard.writeText(
                                                bipSql,
                                            );
                                        }
                                    }}
                                >
                                    Copier
                                </Button>
                            </div>
                            {bipSql === null ? (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                    <p className="font-medium">
                                        {t('semanticCatalog.sqlUnavailable')}
                                    </p>
                                    <p className="mt-1 text-xs">
                                        {t(
                                            'semanticCatalog.sqlUnavailableDescription',
                                        )}
                                    </p>
                                </div>
                            ) : (
                                <pre className="overflow-x-auto rounded-lg border bg-muted p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-foreground">
                                    {bipSql}
                                </pre>
                            )}
                        </div>
                    )}

                    {/* Lot 11E — Agent preview tab */}
                    {activeTab === 'agent' && (
                        <div className="flex flex-col gap-3 p-4">
                            <p className="text-xs text-muted-foreground">
                                {t('agentPreview.description')}
                            </p>

                            {tenantKeys.length === 0 ? (
                                <p className="text-sm text-amber-700 dark:text-amber-400">
                                    {t('agentPreview.noTenant')}
                                </p>
                            ) : queryDescription.trim().length < 10 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('agentPreview.noDescription')}
                                </p>
                            ) : (
                                <>
                                    {agentPreview.error !== null && (
                                        <AlertError
                                            title={t('agentPreview.errorTitle')}
                                            errors={[agentPreview.error]}
                                        />
                                    )}

                                    {agentPreview.run !== null && (
                                        <div className="space-y-2 text-sm">
                                            {agentPreview.run.status === 'completed' && agentPreview.run.result !== null ? (
                                                <QueryResultView
                                                    result={agentPreview.run.result}
                                                    tenantLabel={tenantLabel}
                                                />
                                            ) : agentPreview.isActive ? (
                                                <div className="flex items-center gap-2 text-muted-foreground">
                                                    <Spinner className="size-4" />
                                                    <span>
                                                        {t('agentPreview.iteration', {
                                                            current: agentPreview.run.iteration,
                                                            max: agentPreview.run.max_iterations,
                                                        })}
                                                    </span>
                                                </div>
                                            ) : (
                                                <Badge variant="secondary">
                                                    {agentPreview.run.status}
                                                </Badge>
                                            )}
                                        </div>
                                    )}

                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={agentPreview.isActive}
                                            onClick={() =>
                                                void agentPreview.start(
                                                    queryDescription.trim(),
                                                    tenant,
                                                )
                                            }
                                        >
                                            {agentPreview.dispatching ? (
                                                <>
                                                    <Spinner data-icon="inline-start" />
                                                    {t('agentPreview.running')}
                                                </>
                                            ) : (
                                                <>
                                                    <Bot data-icon="inline-start" />
                                                    {t('agentPreview.button')}
                                                </>
                                            )}
                                        </Button>
                                        {agentPreview.isActive && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => void agentPreview.cancel()}
                                            >
                                                {t('agentPreview.cancel')}
                                            </Button>
                                        )}
                                        {agentPreview.run !== null && !agentPreview.isActive && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={agentPreview.reset}
                                            >
                                                {t('common.reset')}
                                            </Button>
                                        )}
                                    </div>
                                </>
                            )}
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
                                {t('queries.accessLevel')}
                            </Label>
                            {mode === 'create' ? (
                                <div className="flex flex-wrap gap-2">
                                    {(
                                        [
                                            {
                                                value: 'private',
                                                label: t(
                                                    'queries.accessLevelPrivate',
                                                ),
                                                icon: Lock,
                                            },
                                            {
                                                value: 'restricted',
                                                label: t(
                                                    'queries.accessLevelRestricted',
                                                ),
                                                icon: Users,
                                            },
                                            {
                                                value: 'organization',
                                                label: t(
                                                    'queries.accessLevelOrganization',
                                                ),
                                                icon: Globe,
                                            },
                                        ] satisfies Array<{
                                            value: QueryAccessLevel;
                                            label: string;
                                            icon: typeof Lock;
                                        }>
                                    ).map(({ value, label, icon: Icon }) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() =>
                                                setAccessLevel(value)
                                            }
                                            aria-pressed={accessLevel === value}
                                            className={[
                                                'flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm transition-colors',
                                                accessLevel === value
                                                    ? 'border-primary bg-primary/5 font-medium text-primary'
                                                    : 'border-border text-muted-foreground hover:border-primary/40',
                                            ].join(' ')}
                                        >
                                            <Icon className="size-3.5" />
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <div className="flex flex-wrap items-center gap-2">
                                    <QueryAccessLevelBadge
                                        accessLevel={accessLevel}
                                    />
                                    {initialState?.queryId !== undefined && (
                                        <Button
                                            asChild
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={queries.shares.index(
                                                    initialState.queryId,
                                                )}
                                            >
                                                <Share2 className="size-3.5" />
                                                {t('queries.manageSharing')}
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {accessLevelDescriptions[accessLevel]}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <div className="flex items-center justify-between gap-3">
                            <Label
                                htmlFor="qb-description"
                                className="text-xs font-medium"
                            >
                                {t('queries.descriptionLabel')}
                            </Label>
                            <span className="text-[11px] text-muted-foreground tabular-nums">
                                {queryDescription.length}/2000
                            </span>
                        </div>
                        <Textarea
                            id="qb-description"
                            value={queryDescription}
                            onChange={(event) =>
                                setQueryDescription(event.target.value)
                            }
                            maxLength={2000}
                            rows={3}
                            placeholder={t('queries.descriptionPlaceholder')}
                        />
                        <p className="text-[11px] text-muted-foreground">
                            {t('queries.descriptionHint')}
                        </p>
                        {/* Lot 11D — Copilot suggestions */}
                        <CopilotSuggestionPanel
                            intent={queryDescription}
                            allResources={resourceSuggestions}
                            onSelectResource={selectResource}
                        />
                    </div>

                    <div className="grid gap-3 md:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <Label
                                htmlFor="qb-category"
                                className="text-xs font-medium"
                            >
                                {t('queries.category')}
                            </Label>
                            <Select
                                value={categoryId || 'none'}
                                onValueChange={(value) =>
                                    setCategoryId(value === 'none' ? '' : value)
                                }
                            >
                                <SelectTrigger
                                    id="qb-category"
                                    aria-label={t('queries.category')}
                                >
                                    <SelectValue
                                        placeholder={t('queries.noCategory')}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        {t('queries.noCategory')}
                                    </SelectItem>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.id}
                                            value={String(category.id)}
                                        >
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className="size-2 rounded-full bg-muted-foreground"
                                                    style={
                                                        category.color
                                                            ? {
                                                                  backgroundColor:
                                                                      category.color,
                                                              }
                                                            : undefined
                                                    }
                                                />
                                                {category.name}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label
                                htmlFor="qb-tags"
                                className="text-xs font-medium"
                            >
                                {t('queries.tags')}
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id="qb-tags"
                                    list="qb-tag-suggestions"
                                    value={tagInput}
                                    onChange={(event) =>
                                        setTagInput(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Enter' ||
                                            event.key === ','
                                        ) {
                                            event.preventDefault();
                                            addTag();
                                        }
                                    }}
                                    onBlur={() => addTag()}
                                    placeholder={t('queries.tagsPlaceholder')}
                                    disabled={tags.length >= 10}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() => addTag()}
                                    disabled={
                                        tagInput.trim() === '' ||
                                        tags.length >= 10
                                    }
                                    aria-label={t('queries.addTag')}
                                >
                                    <Tag className="size-4" />
                                </Button>
                            </div>
                            <datalist id="qb-tag-suggestions">
                                {tagSuggestions.map((tag) => (
                                    <option key={tag.id} value={tag.label}>
                                        {tag.name}
                                    </option>
                                ))}
                            </datalist>
                            {tags.length > 0 && (
                                <div className="flex flex-wrap gap-1.5 pt-1">
                                    {tags.map((tag) => (
                                        <Badge
                                            key={tag}
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            {tagLabel(tag)}
                                            <button
                                                type="button"
                                                onClick={() => removeTag(tag)}
                                                aria-label={t(
                                                    'queries.removeTag',
                                                    {
                                                        name: tag,
                                                    },
                                                )}
                                            >
                                                <X className="size-3" />
                                            </button>
                                        </Badge>
                                    ))}
                                </div>
                            )}
                            <p className="text-[11px] text-muted-foreground">
                                <FolderOpen className="mr-1 inline size-3" />
                                {t('queries.tagsHint')}
                            </p>
                        </div>
                    </div>

                    {saveErrors.length > 0 && (
                        <AlertError
                            title="Enregistrement impossible"
                            errors={saveErrors}
                        />
                    )}

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
