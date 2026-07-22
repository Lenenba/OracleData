import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleHelp,
    Database,
    FileClock,
    FilePlus2,
    LockKeyhole,
    RefreshCw,
    RotateCcw,
    Search,
    Send,
    UserCog,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { TemplateCertificationCard } from '@/components/queries/template-certification-card';
import { TemplateGovernanceStatusBadge } from '@/components/queries/template-governance-status-badge';
import { TemplateQualityCard } from '@/components/queries/template-quality-card';
import { TemplateVersionComparison } from '@/components/queries/template-version-comparison';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import queryTemplateGovernance from '@/routes/query-template-governance';
import delegations from '@/routes/query-template-governance/delegations';
import technicalOwner from '@/routes/query-template-governance/technical-owner';
import type {
    GovernanceCategory,
    GovernanceRoleCandidate,
    GovernanceUser,
    LaravelPaginator,
    OracleResourceSuggestion,
    QueryTemplateGovernanceCapabilities,
    QueryTemplateGovernanceDetail,
    QueryTemplateGovernanceRole,
    QueryTemplateGovernanceVersion,
    QueryTemplateGovernanceVersionOption,
    QueryTemplateQualityOverview,
} from '@/types/query-template-governance';

type ShowProps = {
    template: QueryTemplateGovernanceDetail;
    versions: LaravelPaginator<QueryTemplateGovernanceVersion>;
    version_options: QueryTemplateGovernanceVersionOption[];
    categories: GovernanceCategory[];
    businessOwnerCandidates: GovernanceUser[];
    ownerSearch: string;
    governanceCapabilities: QueryTemplateGovernanceCapabilities;
    governanceRoleCandidates: GovernanceRoleCandidate[];
    technicalOwnerCandidates: GovernanceUser[];
    oracleResources: OracleResourceSuggestion[];
    quality: QueryTemplateQualityOverview;
    tenants: Record<string, string>;
    defaultTenant: string | null;
};

type Feedback = { type: 'success' | 'error'; message: string } | null;
type Confirmation = 'submit' | 'publish' | 'archive' | null;

const LOCALES = [
    { value: 'fr', label: 'taxonomy.french' },
    { value: 'en', label: 'taxonomy.english' },
    { value: 'es', label: 'taxonomy.spanish' },
] as const;

const governanceIndexUrl = queryTemplateGovernance.index.url();
const governanceShowUrl = (slug: string) =>
    queryTemplateGovernance.show.url(slug);
const versionsUrl = (slug: string) =>
    queryTemplateGovernance.versions.store.url(slug);
const versionUrl = (slug: string, versionId: number) =>
    queryTemplateGovernance.versions.update.url([slug, versionId]);
const submitUrl = (slug: string, versionId: number) =>
    queryTemplateGovernance.versions.submit.url([slug, versionId]);
const publishUrl = (slug: string, versionId: number) =>
    queryTemplateGovernance.versions.publish.url([slug, versionId]);
const restoreUrl = (slug: string, versionId: number) =>
    queryTemplateGovernance.versions.restore.url([slug, versionId]);
const archiveUrl = (slug: string) => queryTemplateGovernance.archive.url(slug);

function firstError(errors: Record<string, string>, fallback: string) {
    return Object.values(errors)[0] ?? fallback;
}

function stringValue(value: unknown, fallback = '') {
    return typeof value === 'string' ? value : fallback;
}

function numberValue(value: unknown, fallback = 0) {
    return typeof value === 'number' && Number.isFinite(value)
        ? value
        : fallback;
}

function DraftEditor({
    template,
    version,
    categories,
    candidates,
    ownerSearch,
    oracleResources,
    canUpdateTechnicalDefinition,
}: {
    template: QueryTemplateGovernanceDetail;
    version: QueryTemplateGovernanceVersion;
    categories: GovernanceCategory[];
    candidates: GovernanceUser[];
    ownerSearch: string;
    oracleResources: OracleResourceSuggestion[];
    canUpdateTechnicalDefinition: boolean;
}) {
    const { t } = useI18n();
    const [name, setName] = useState(
        stringValue(version.definition.name, template.name),
    );
    const [description, setDescription] = useState(
        stringValue(version.definition.description, template.description ?? ''),
    );
    const [categoryId, setCategoryId] = useState(
        version.definition.category_id === null ||
            version.definition.category_id === undefined
            ? 'none'
            : String(version.definition.category_id),
    );
    const [sortOrder, setSortOrder] = useState(
        String(numberValue(version.definition.sort_order, template.sort_order)),
    );
    const [changeSummary, setChangeSummary] = useState(
        version.change_summary ?? '',
    );
    const [businessOwnerId, setBusinessOwnerId] = useState(
        template.business_owner ? String(template.business_owner.id) : 'none',
    );
    const [reviewDueAt, setReviewDueAt] = useState(
        template.review_due_at ?? '',
    );
    const [resourceKey, setResourceKey] = useState(
        stringValue(version.definition.resource_key, template.resource_key),
    );
    const [parametersJson, setParametersJson] = useState(() =>
        JSON.stringify(
            version.definition.parameters ?? template.parameters,
            null,
            2,
        ),
    );
    const [parameterDefinitionsJson, setParameterDefinitionsJson] = useState(
        () =>
            JSON.stringify(
                version.definition.parameter_definitions ??
                    template.parameter_definitions,
                null,
                2,
            ),
    );
    const [qualityRulesJson, setQualityRulesJson] = useState(() =>
        JSON.stringify(version.quality_rules ?? [], null, 2),
    );
    const [translations, setTranslations] = useState(
        () =>
            Object.fromEntries(
                LOCALES.map(({ value }) => {
                    const translation =
                        version.translations[value] ??
                        template.translations[value] ??
                        {};

                    return [
                        value,
                        {
                            name: translation.name ?? '',
                            description: translation.description ?? '',
                        },
                    ];
                }),
            ) as Record<
                'fr' | 'en' | 'es',
                { name: string; description: string }
            >,
    );
    const [candidateSearch, setCandidateSearch] = useState(ownerSearch);
    const [ownerSearchPending, setOwnerSearchPending] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [feedback, setFeedback] = useState<Feedback>(null);

    const ownerCandidates = useMemo(() => {
        const byId = new Map<number, GovernanceUser>();

        if (template.business_owner) {
            byId.set(template.business_owner.id, template.business_owner);
        }

        candidates.forEach((candidate) => byId.set(candidate.id, candidate));

        return [...byId.values()];
    }, [candidates, template.business_owner]);
    const selectedResource = oracleResources.find(
        (resource) => resource.key === resourceKey,
    );

    function updateTranslation(
        locale: 'fr' | 'en' | 'es',
        field: 'name' | 'description',
        value: string,
    ) {
        setTranslations((current) => ({
            ...current,
            [locale]: { ...current[locale], [field]: value },
        }));
    }

    function searchOwners() {
        setOwnerSearchPending(true);
        router.get(
            governanceShowUrl(template.slug),
            { owner_search: candidateSearch.trim() || undefined },
            {
                only: ['businessOwnerCandidates', 'ownerSearch'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setOwnerSearchPending(false),
            },
        );
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setErrors({});
        setFeedback(null);

        let parsedQualityRules: unknown;

        try {
            parsedQualityRules = JSON.parse(qualityRulesJson);
        } catch {
            setErrors({
                quality_rules: t('templateGovernance.qualityRulesJsonInvalid'),
            });
            setFeedback({
                type: 'error',
                message: t('templateGovernance.qualityRulesJsonError'),
            });

            return;
        }

        if (
            !Array.isArray(parsedQualityRules) ||
            parsedQualityRules.some(
                (rule) =>
                    rule === null ||
                    typeof rule !== 'object' ||
                    Array.isArray(rule),
            )
        ) {
            setErrors({
                quality_rules: t(
                    'templateGovernance.qualityRulesJsonArrayRequired',
                ),
            });
            setFeedback({
                type: 'error',
                message: t('templateGovernance.qualityRulesJsonError'),
            });

            return;
        }

        let technicalDefinition:
            | {
                  resource_key: string;
                  parameters: Record<string, unknown>;
                  parameter_definitions: Array<Record<string, unknown>>;
              }
            | undefined;

        if (canUpdateTechnicalDefinition) {
            const clientErrors: Record<string, string> = {};
            let parsedParameters: unknown;
            let parsedParameterDefinitions: unknown;

            try {
                parsedParameters = JSON.parse(parametersJson);
            } catch {
                clientErrors.parameters = t(
                    'templateGovernance.parametersJsonInvalid',
                );
            }

            try {
                parsedParameterDefinitions = JSON.parse(
                    parameterDefinitionsJson,
                );
            } catch {
                clientErrors.parameter_definitions = t(
                    'templateGovernance.parameterDefinitionsJsonInvalid',
                );
            }

            if (
                parsedParameters !== undefined &&
                (parsedParameters === null ||
                    typeof parsedParameters !== 'object' ||
                    Array.isArray(parsedParameters))
            ) {
                clientErrors.parameters = t(
                    'templateGovernance.parametersJsonObjectRequired',
                );
            }

            if (
                parsedParameterDefinitions !== undefined &&
                (!Array.isArray(parsedParameterDefinitions) ||
                    parsedParameterDefinitions.some(
                        (definition) =>
                            definition === null ||
                            typeof definition !== 'object' ||
                            Array.isArray(definition),
                    ))
            ) {
                clientErrors.parameter_definitions = t(
                    'templateGovernance.parameterDefinitionsJsonArrayRequired',
                );
            }

            if (resourceKey === '') {
                clientErrors.resource_key = t(
                    'templateGovernance.oracleResourceRequired',
                );
            }

            if (Object.keys(clientErrors).length > 0) {
                setErrors(clientErrors);
                setFeedback({
                    type: 'error',
                    message: t(
                        'templateGovernance.technicalDefinitionJsonError',
                    ),
                });

                return;
            }

            technicalDefinition = {
                resource_key: resourceKey,
                parameters: {
                    ...(parsedParameters as Record<string, unknown>),
                    resource_key: resourceKey,
                },
                parameter_definitions: parsedParameterDefinitions as Array<
                    Record<string, unknown>
                >,
            };
        }

        const payload = {
            name: name.trim(),
            description: description.trim() || null,
            category_id: categoryId === 'none' ? null : Number(categoryId),
            sort_order: Number(sortOrder),
            translations,
            change_summary: changeSummary.trim() || null,
            business_owner_user_id:
                businessOwnerId === 'none' ? null : Number(businessOwnerId),
            review_due_at: reviewDueAt || null,
            quality_rules: parsedQualityRules as Array<Record<string, unknown>>,
            template_lock_version: template.lock_version,
            version_lock_version: version.lock_version,
            ...(technicalDefinition ?? {}),
        };

        router.patch(
            versionUrl(template.slug, version.id),
            payload as NonNullable<Parameters<typeof router.patch>[1]>,
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (validationErrors) => {
                    setErrors(validationErrors);
                    setFeedback({
                        type: 'error',
                        message: firstError(
                            validationErrors,
                            t('templateGovernance.saveError'),
                        ),
                    });
                },
                onSuccess: () =>
                    setFeedback({
                        type: 'success',
                        message: t('templateGovernance.saveSuccess'),
                    }),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>
                            {t('templateGovernance.editDraftTitle', {
                                version: version.version_number,
                            })}
                        </CardTitle>
                        <CardDescription>
                            {t('templateGovernance.editDraftDescription')}
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        <TemplateGovernanceStatusBadge
                            status={version.status}
                        />
                        <Badge variant="outline">
                            <LockKeyhole aria-hidden="true" />
                            {t('templateGovernance.lockVersion', {
                                version: version.lock_version,
                            })}
                        </Badge>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form onSubmit={save} className="space-y-6">
                    {feedback && (
                        <Alert
                            variant={
                                feedback.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                            aria-live="polite"
                        >
                            <CircleHelp aria-hidden="true" />
                            <AlertTitle>
                                {feedback.type === 'error'
                                    ? t('templateGovernance.errorTitle')
                                    : t('templateGovernance.successTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {feedback.message}
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="template-governance-name">
                                {t('templateGovernance.nameLabel')}
                            </Label>
                            <Input
                                id="template-governance-name"
                                value={name}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                maxLength={255}
                                required
                                disabled={processing}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="template-governance-description">
                                {t('templateGovernance.descriptionLabel')}
                            </Label>
                            <Textarea
                                id="template-governance-description"
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                                maxLength={5000}
                                rows={4}
                                disabled={processing}
                            />
                            <InputError message={errors.description} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="template-governance-category">
                                {t('templateGovernance.categoryLabel')}
                            </Label>
                            <select
                                id="template-governance-category"
                                value={categoryId}
                                onChange={(event) =>
                                    setCategoryId(event.target.value)
                                }
                                disabled={processing}
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                <option value="none">
                                    {t('templateGovernance.noCategory')}
                                </option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.slug}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.category_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="template-governance-sort-order">
                                {t('templateGovernance.sortOrderLabel')}
                            </Label>
                            <Input
                                id="template-governance-sort-order"
                                type="number"
                                min={0}
                                max={100000}
                                value={sortOrder}
                                onChange={(event) =>
                                    setSortOrder(event.target.value)
                                }
                                required
                                disabled={processing}
                            />
                            <InputError message={errors.sort_order} />
                        </div>
                    </div>

                    {canUpdateTechnicalDefinition && (
                        <section className="space-y-4 rounded-lg border p-4">
                            <div>
                                <h3 className="flex items-center gap-2 font-semibold">
                                    <Database
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t(
                                        'templateGovernance.oracleDefinitionTitle',
                                    )}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'templateGovernance.oracleDefinitionDescription',
                                    )}
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="template-oracle-resource">
                                    {t(
                                        'templateGovernance.oracleResourceLabel',
                                    )}
                                </Label>
                                <select
                                    id="template-oracle-resource"
                                    value={resourceKey}
                                    onChange={(event) =>
                                        setResourceKey(event.target.value)
                                    }
                                    disabled={processing}
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    {oracleResources.map((resource) => (
                                        <option
                                            key={resource.key}
                                            value={resource.key}
                                        >
                                            {resource.label} — {resource.domain}
                                        </option>
                                    ))}
                                </select>
                                {selectedResource && (
                                    <p className="text-xs text-muted-foreground">
                                        {selectedResource.description}
                                    </p>
                                )}
                                <InputError
                                    message={
                                        errors.resource_key ??
                                        errors['definition.resource_key']
                                    }
                                />
                            </div>
                            <div className="grid gap-4 lg:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="template-oracle-parameters">
                                        {t(
                                            'templateGovernance.parametersJsonLabel',
                                        )}
                                    </Label>
                                    <Textarea
                                        id="template-oracle-parameters"
                                        value={parametersJson}
                                        onChange={(event) =>
                                            setParametersJson(
                                                event.target.value,
                                            )
                                        }
                                        rows={12}
                                        spellCheck={false}
                                        disabled={processing}
                                        className="font-mono text-xs"
                                    />
                                    <InputError
                                        message={
                                            errors.parameters ??
                                            errors['definition.parameters']
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="template-parameter-definitions">
                                        {t(
                                            'templateGovernance.parameterDefinitionsJsonLabel',
                                        )}
                                    </Label>
                                    <Textarea
                                        id="template-parameter-definitions"
                                        value={parameterDefinitionsJson}
                                        onChange={(event) =>
                                            setParameterDefinitionsJson(
                                                event.target.value,
                                            )
                                        }
                                        rows={12}
                                        spellCheck={false}
                                        disabled={processing}
                                        className="font-mono text-xs"
                                    />
                                    <InputError
                                        message={
                                            errors.parameter_definitions ??
                                            errors[
                                                'definition.parameter_definitions'
                                            ]
                                        }
                                    />
                                </div>
                            </div>
                        </section>
                    )}

                    <section className="space-y-4 rounded-lg border p-4">
                        <div>
                            <h3 className="font-semibold">
                                {t('templateGovernance.qualityRulesTitle')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'templateGovernance.qualityRulesDescription',
                                )}
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="template-quality-rules">
                                {t('templateGovernance.qualityRulesJsonLabel')}
                            </Label>
                            <Textarea
                                id="template-quality-rules"
                                value={qualityRulesJson}
                                onChange={(event) =>
                                    setQualityRulesJson(event.target.value)
                                }
                                rows={12}
                                spellCheck={false}
                                disabled={processing}
                                className="font-mono text-xs"
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('templateGovernance.qualityRulesJsonHelp')}
                            </p>
                            <InputError
                                message={
                                    errors.quality_rules ??
                                    errors['definition.quality_rules']
                                }
                            />
                        </div>
                    </section>

                    <section className="space-y-3">
                        <div>
                            <h3 className="font-semibold">
                                {t('templateGovernance.translationsTitle')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'templateGovernance.translationsDescription',
                                )}
                            </p>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-3">
                            {LOCALES.map((locale) => (
                                <div
                                    key={locale.value}
                                    className="space-y-4 rounded-lg border p-4"
                                >
                                    <p className="text-sm font-semibold">
                                        {t(locale.label)}
                                    </p>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`template-translation-${locale.value}-name`}
                                        >
                                            {t(
                                                'templateGovernance.translationName',
                                            )}
                                        </Label>
                                        <Input
                                            id={`template-translation-${locale.value}-name`}
                                            value={
                                                translations[locale.value].name
                                            }
                                            onChange={(event) =>
                                                updateTranslation(
                                                    locale.value,
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={255}
                                            required
                                            disabled={processing}
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `translations.${locale.value}.name`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`template-translation-${locale.value}-description`}
                                        >
                                            {t(
                                                'templateGovernance.translationDescription',
                                            )}
                                        </Label>
                                        <Textarea
                                            id={`template-translation-${locale.value}-description`}
                                            value={
                                                translations[locale.value]
                                                    .description
                                            }
                                            onChange={(event) =>
                                                updateTranslation(
                                                    locale.value,
                                                    'description',
                                                    event.target.value,
                                                )
                                            }
                                            maxLength={5000}
                                            rows={4}
                                            disabled={processing}
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `translations.${locale.value}.description`
                                                ]
                                            }
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="space-y-4 rounded-lg border p-4">
                        <div>
                            <h3 className="font-semibold">
                                {t('templateGovernance.ownershipTitle')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {t('templateGovernance.ownershipDescription')}
                            </p>
                        </div>
                        <div
                            className="flex flex-col gap-2 sm:flex-row sm:items-end"
                            role="search"
                        >
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="business-owner-search">
                                    {t('templateGovernance.ownerSearchLabel')}
                                </Label>
                                <div className="relative">
                                    <Search
                                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        id="business-owner-search"
                                        type="search"
                                        value={candidateSearch}
                                        onChange={(event) =>
                                            setCandidateSearch(
                                                event.target.value,
                                            )
                                        }
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                searchOwners();
                                            }
                                        }}
                                        className="pl-9"
                                        maxLength={100}
                                        placeholder={t(
                                            'templateGovernance.ownerSearchPlaceholder',
                                        )}
                                        disabled={
                                            processing || ownerSearchPending
                                        }
                                    />
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={searchOwners}
                                disabled={processing || ownerSearchPending}
                            >
                                {ownerSearchPending && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                {t('templateGovernance.searchOwner')}
                            </Button>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="template-business-owner">
                                    {t('templateGovernance.businessOwnerLabel')}
                                </Label>
                                <select
                                    id="template-business-owner"
                                    value={businessOwnerId}
                                    onChange={(event) =>
                                        setBusinessOwnerId(event.target.value)
                                    }
                                    disabled={processing}
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    <option value="none">
                                        {t(
                                            'templateGovernance.noBusinessOwner',
                                        )}
                                    </option>
                                    {ownerCandidates.map((candidate) => (
                                        <option
                                            key={candidate.id}
                                            value={candidate.id}
                                        >
                                            {candidate.name}
                                            {candidate.email
                                                ? ` — ${candidate.email}`
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.business_owner_user_id}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="template-review-date">
                                    {t('templateGovernance.reviewDateLabel')}
                                </Label>
                                <Input
                                    id="template-review-date"
                                    type="date"
                                    value={reviewDueAt}
                                    onChange={(event) =>
                                        setReviewDueAt(event.target.value)
                                    }
                                    disabled={processing}
                                />
                                <InputError message={errors.review_due_at} />
                            </div>
                        </div>
                    </section>

                    <div className="grid gap-2">
                        <Label htmlFor="template-change-summary">
                            {t('templateGovernance.changeSummaryLabel')}
                        </Label>
                        <Textarea
                            id="template-change-summary"
                            value={changeSummary}
                            onChange={(event) =>
                                setChangeSummary(event.target.value)
                            }
                            maxLength={2000}
                            rows={3}
                            disabled={processing}
                            placeholder={t(
                                'templateGovernance.changeSummaryPlaceholder',
                            )}
                        />
                        <InputError message={errors.change_summary} />
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing && (
                                <Spinner aria-label={t('common.loading')} />
                            )}
                            {t('templateGovernance.saveDraft')}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

const GOVERNANCE_ROLES = [
    {
        value: 'template_editor',
        label: 'templateGovernance.editorRoleLabel',
    },
    {
        value: 'template_publisher',
        label: 'templateGovernance.publisherRoleLabel',
    },
] as const satisfies ReadonlyArray<{
    value: QueryTemplateGovernanceRole;
    label: string;
}>;

function ScopedDelegationsCard({
    template,
    candidates,
}: {
    template: QueryTemplateGovernanceDetail;
    candidates: GovernanceRoleCandidate[];
}) {
    const { t } = useI18n();
    const [selectedUserId, setSelectedUserId] = useState(
        candidates[0] ? String(candidates[0].id) : '',
    );
    const [roles, setRoles] = useState<QueryTemplateGovernanceRole[]>(
        candidates[0]?.roles ?? [],
    );
    const [processing, setProcessing] = useState(false);
    const [feedback, setFeedback] = useState<Feedback>(null);
    const selectedCandidate = candidates.find(
        (candidate) => String(candidate.id) === selectedUserId,
    );

    function selectCandidate(userId: string) {
        const candidate = candidates.find((item) => String(item.id) === userId);

        setSelectedUserId(userId);
        setRoles(candidate?.roles ?? []);
        setFeedback(null);
    }

    function toggleRole(role: QueryTemplateGovernanceRole, checked: boolean) {
        setRoles((current) =>
            checked
                ? [...new Set([...current, role])]
                : current.filter((item) => item !== role),
        );
    }

    function saveRoles() {
        if (!selectedCandidate) {
            return;
        }

        setFeedback(null);
        router.patch(
            delegations.update.url([template.slug, selectedCandidate.id]),
            { roles },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) =>
                    setFeedback({
                        type: 'error',
                        message: firstError(
                            errors,
                            t('templateGovernance.delegationSaveError'),
                        ),
                    }),
                onSuccess: () =>
                    setFeedback({
                        type: 'success',
                        message: t('templateGovernance.delegationSaveSuccess'),
                    }),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Users className="size-5" aria-hidden="true" />
                    {t('templateGovernance.delegationsTitle')}
                </CardTitle>
                <CardDescription>
                    {t('templateGovernance.delegationsDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {candidates.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('templateGovernance.noDelegationCandidates')}
                    </p>
                ) : (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="governance-role-candidate">
                                {t(
                                    'templateGovernance.delegationCandidateLabel',
                                )}
                            </Label>
                            <select
                                id="governance-role-candidate"
                                value={selectedUserId}
                                onChange={(event) =>
                                    selectCandidate(event.target.value)
                                }
                                disabled={processing}
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                {candidates.map((candidate) => (
                                    <option
                                        key={candidate.id}
                                        value={candidate.id}
                                    >
                                        {candidate.name} — {candidate.email}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex flex-wrap gap-4 rounded-md border p-3">
                            {GOVERNANCE_ROLES.map((role) => (
                                <div
                                    key={role.value}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`governance-role-${role.value}`}
                                        checked={roles.includes(role.value)}
                                        onCheckedChange={(checked) =>
                                            toggleRole(
                                                role.value,
                                                checked === true,
                                            )
                                        }
                                        disabled={processing}
                                    />
                                    <Label
                                        htmlFor={`governance-role-${role.value}`}
                                    >
                                        {t(role.label)}
                                    </Label>
                                </div>
                            ))}
                        </div>
                        {feedback && (
                            <p
                                className={
                                    feedback.type === 'error'
                                        ? 'text-sm text-destructive'
                                        : 'text-sm text-muted-foreground'
                                }
                                aria-live="polite"
                            >
                                {feedback.message}
                            </p>
                        )}
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                size="sm"
                                onClick={saveRoles}
                                disabled={processing || !selectedCandidate}
                            >
                                {processing && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                {t('templateGovernance.saveDelegation')}
                            </Button>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function TechnicalOwnerCard({
    template,
    candidates,
}: {
    template: QueryTemplateGovernanceDetail;
    candidates: GovernanceUser[];
}) {
    const { t } = useI18n();
    const [technicalOwnerId, setTechnicalOwnerId] = useState(
        template.technical_owner ? String(template.technical_owner.id) : 'none',
    );
    const [processing, setProcessing] = useState(false);
    const [feedback, setFeedback] = useState<Feedback>(null);
    const ownerCandidates = useMemo(() => {
        const byId = new Map<number, GovernanceUser>();

        if (template.technical_owner) {
            byId.set(template.technical_owner.id, template.technical_owner);
        }

        candidates.forEach((candidate) => byId.set(candidate.id, candidate));

        return [...byId.values()];
    }, [candidates, template.technical_owner]);

    function saveTechnicalOwner() {
        setFeedback(null);
        router.patch(
            technicalOwner.update.url(template.slug),
            {
                technical_owner_user_id:
                    technicalOwnerId === 'none'
                        ? null
                        : Number(technicalOwnerId),
                lock_version: template.lock_version,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) =>
                    setFeedback({
                        type: 'error',
                        message: firstError(
                            errors,
                            t('templateGovernance.technicalOwnerSaveError'),
                        ),
                    }),
                onSuccess: () =>
                    setFeedback({
                        type: 'success',
                        message: t(
                            'templateGovernance.technicalOwnerSaveSuccess',
                        ),
                    }),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <UserCog className="size-5" aria-hidden="true" />
                    {t('templateGovernance.technicalOwnerTitle')}
                </CardTitle>
                <CardDescription>
                    {t('templateGovernance.technicalOwnerDescription')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-2">
                    <Label htmlFor="template-technical-owner">
                        {t('templateGovernance.technicalOwnerLabel')}
                    </Label>
                    <select
                        id="template-technical-owner"
                        value={technicalOwnerId}
                        onChange={(event) =>
                            setTechnicalOwnerId(event.target.value)
                        }
                        disabled={processing}
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option value="none">
                            {t('templateGovernance.noTechnicalOwner')}
                        </option>
                        {ownerCandidates.map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>
                                {candidate.name}
                                {candidate.email ? ` — ${candidate.email}` : ''}
                            </option>
                        ))}
                    </select>
                </div>
                {feedback && (
                    <p
                        className={
                            feedback.type === 'error'
                                ? 'text-sm text-destructive'
                                : 'text-sm text-muted-foreground'
                        }
                        aria-live="polite"
                    >
                        {feedback.message}
                    </p>
                )}
                <div className="flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        onClick={saveTechnicalOwner}
                        disabled={processing}
                    >
                        {processing && (
                            <Spinner aria-label={t('common.loading')} />
                        )}
                        {t('templateGovernance.saveTechnicalOwner')}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function QueryTemplateGovernanceShow({
    template,
    versions,
    version_options,
    categories,
    businessOwnerCandidates,
    ownerSearch,
    governanceCapabilities,
    governanceRoleCandidates,
    technicalOwnerCandidates,
    oracleResources,
    quality,
    tenants,
    defaultTenant,
}: ShowProps) {
    const { t, formatDate } = useI18n();
    const [createDraftOpen, setCreateDraftOpen] = useState(false);
    const [draftSummary, setDraftSummary] = useState('');
    const [confirmation, setConfirmation] = useState<Confirmation>(null);
    const [pending, setPending] = useState<string | null>(null);
    const [navigationPending, setNavigationPending] = useState(false);
    const [feedback, setFeedback] = useState<Feedback>(null);
    const [restoreSource, setRestoreSource] =
        useState<QueryTemplateGovernanceVersion | null>(null);
    const [restoreSummary, setRestoreSummary] = useState('');
    const [restoreError, setRestoreError] = useState<string | null>(null);

    const openVersion = template.open_version
        ? (versions.data.find(
              (version) => version.id === template.open_version?.id,
          ) ?? null)
        : null;
    const qualityVersion = template.open_version ?? template.published_version;
    const canCreateDraft =
        governanceCapabilities.create_draft &&
        template.governance_status === 'published' &&
        template.published_version !== null &&
        template.open_version === null;
    const canArchive =
        governanceCapabilities.archive &&
        template.governance_status === 'published';
    const parameterCount = Array.isArray(template.parameter_definitions)
        ? template.parameter_definitions.length
        : 0;

    function mutationError(errors: Record<string, string>, fallback: string) {
        setFeedback({ type: 'error', message: firstError(errors, fallback) });
    }

    function createDraft(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!canCreateDraft) {
            return;
        }

        setFeedback(null);
        router.post(
            versionsUrl(template.slug),
            {
                lock_version: template.lock_version,
                change_summary: draftSummary.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => setPending('create'),
                onError: (errors) => {
                    setCreateDraftOpen(false);
                    mutationError(
                        errors,
                        t('templateGovernance.createDraftError'),
                    );
                },
                onSuccess: () => {
                    setCreateDraftOpen(false);
                    setDraftSummary('');
                    setFeedback({
                        type: 'success',
                        message: t('templateGovernance.createDraftSuccess'),
                    });
                },
                onFinish: () => setPending(null),
            },
        );
    }

    function restoreVersion(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (restoreSource === null || !governanceCapabilities.restore) {
            return;
        }

        setFeedback(null);
        setRestoreError(null);
        router.post(
            restoreUrl(template.slug, restoreSource.id),
            {
                template_lock_version: template.lock_version,
                version_lock_version: restoreSource.lock_version,
                change_summary: restoreSummary.trim() || null,
            },
            {
                preserveScroll: true,
                onStart: () => setPending('restore'),
                onError: (errors) =>
                    setRestoreError(
                        firstError(
                            errors,
                            t('templateGovernance.restoreError'),
                        ),
                    ),
                onSuccess: () => {
                    setRestoreSource(null);
                    setRestoreSummary('');
                    setFeedback({
                        type: 'success',
                        message: t('templateGovernance.restoreSuccess'),
                    });
                },
                onFinish: () => setPending(null),
            },
        );
    }

    function confirmAction() {
        if (confirmation === null) {
            return;
        }

        const action = confirmation;
        const version = openVersion;

        if (
            (action === 'archive' && !canArchive) ||
            (action === 'submit' && !governanceCapabilities.submit) ||
            (action === 'publish' && !governanceCapabilities.publish)
        ) {
            return;
        }

        let route: string;
        let payload:
            | { lock_version: number }
            | {
                  template_lock_version: number;
                  version_lock_version: number;
              };

        if (action === 'archive') {
            route = archiveUrl(template.slug);
            payload = { lock_version: template.lock_version };
        } else {
            if (version === null) {
                return;
            }

            route =
                action === 'submit'
                    ? submitUrl(template.slug, version.id)
                    : publishUrl(template.slug, version.id);
            payload = {
                template_lock_version: template.lock_version,
                version_lock_version: version.lock_version,
            };
        }

        setFeedback(null);
        router.post(route, payload, {
            preserveScroll: true,
            onStart: () => setPending(action),
            onError: (errors) => {
                setConfirmation(null);
                mutationError(errors, t('templateGovernance.actionError'));
            },
            onSuccess: () => {
                setConfirmation(null);
                setFeedback({
                    type: 'success',
                    message:
                        action === 'submit'
                            ? t('templateGovernance.submitSuccess')
                            : action === 'publish'
                              ? t('templateGovernance.publishSuccess')
                              : t('templateGovernance.archiveSuccess'),
                });
            },
            onFinish: () => setPending(null),
        });
    }

    function loadVersionPage(page: number) {
        setNavigationPending(true);
        router.get(
            governanceShowUrl(template.slug),
            {
                version_page: page,
                owner_search: ownerSearch || undefined,
            },
            {
                only: ['versions'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setNavigationPending(false),
            },
        );
    }

    const confirmationTitle =
        confirmation === 'submit'
            ? t('templateGovernance.submitConfirmTitle')
            : confirmation === 'publish'
              ? t('templateGovernance.publishConfirmTitle')
              : confirmation === 'archive'
                ? t('templateGovernance.archiveConfirmTitle')
                : '';
    const confirmationDescription =
        confirmation === 'submit'
            ? t('templateGovernance.submitConfirmDescription')
            : confirmation === 'publish'
              ? t('templateGovernance.publishConfirmDescription')
              : confirmation === 'archive'
                ? t('templateGovernance.archiveConfirmDescription')
                : '';

    return (
        <>
            <Head title={template.name} />
            <h1 className="sr-only">{template.name}</h1>

            <div className="space-y-5">
                <Heading
                    title={template.name}
                    description={t('templateGovernance.detailDescription', {
                        slug: template.slug,
                    })}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={governanceIndexUrl}>
                                    {t('templateGovernance.backToList')}
                                </Link>
                            </Button>
                            {canCreateDraft && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => setCreateDraftOpen(true)}
                                >
                                    <FilePlus2 aria-hidden="true" />
                                    {t('templateGovernance.createDraft')}
                                </Button>
                            )}
                            {canArchive && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setConfirmation('archive')}
                                >
                                    <Archive aria-hidden="true" />
                                    {t('templateGovernance.archive')}
                                </Button>
                            )}
                        </div>
                    }
                />

                {feedback && (
                    <Alert
                        variant={
                            feedback.type === 'error'
                                ? 'destructive'
                                : 'default'
                        }
                        aria-live="polite"
                    >
                        <CircleHelp aria-hidden="true" />
                        <AlertTitle>
                            {feedback.type === 'error'
                                ? t('templateGovernance.errorTitle')
                                : t('templateGovernance.successTitle')}
                        </AlertTitle>
                        <AlertDescription className="space-y-3">
                            <p>{feedback.message}</p>
                            {feedback.type === 'error' && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setFeedback(null);
                                        router.reload();
                                    }}
                                >
                                    <RefreshCw aria-hidden="true" />
                                    {t('templateGovernance.reloadState')}
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {template.is_review_overdue && (
                    <Alert variant="destructive">
                        <AlertTriangle aria-hidden="true" />
                        <AlertTitle>
                            {t('templateGovernance.reviewOverdue')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('templateGovernance.reviewOverdueDescription')}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 xl:grid-cols-3">
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <CardTitle>
                                        {t('templateGovernance.summaryTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {template.description ??
                                            t(
                                                'templateGovernance.noDescription',
                                            )}
                                    </CardDescription>
                                </div>
                                <TemplateGovernanceStatusBadge
                                    status={template.governance_status}
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <p className="font-medium">
                                    {t('templateGovernance.resourceLabel')}
                                </p>
                                <p className="text-muted-foreground">
                                    {template.resource_key}
                                </p>
                                <code className="text-xs break-all text-muted-foreground">
                                    {template.resource_path}
                                </code>
                            </div>
                            <div>
                                <p className="font-medium">
                                    {t('templateGovernance.parametersLabel')}
                                </p>
                                <p className="text-muted-foreground">
                                    {t(
                                        'templateGovernance.parameterDefinitionCount',
                                        { count: parameterCount },
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        governanceCapabilities.update_technical_definition
                                            ? 'templateGovernance.technicalDefinitionEditable'
                                            : 'templateGovernance.technicalDefinitionLocked',
                                    )}
                                </p>
                            </div>
                            <div>
                                <p className="font-medium">
                                    {t('templateGovernance.businessOwnerLabel')}
                                </p>
                                <p className="text-muted-foreground">
                                    {template.business_owner?.name ??
                                        t('templateGovernance.noBusinessOwner')}
                                </p>
                            </div>
                            <div>
                                <p className="font-medium">
                                    {t('templateGovernance.reviewDateLabel')}
                                </p>
                                <p className="text-muted-foreground">
                                    {template.review_due_at
                                        ? formatDate(template.review_due_at, {
                                              dateStyle: 'long',
                                          })
                                        : t('templateGovernance.noReviewDate')}
                                </p>
                            </div>
                            <div>
                                <p className="font-medium">
                                    {t(
                                        'templateGovernance.technicalOwnerLabel',
                                    )}
                                </p>
                                <p className="text-muted-foreground">
                                    {template.technical_owner?.name ??
                                        t(
                                            'templateGovernance.noTechnicalOwner',
                                        )}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('templateGovernance.workflowTitle')}
                            </CardTitle>
                            <CardDescription>
                                {t('templateGovernance.workflowDescription')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex items-center justify-between gap-3">
                                <span>
                                    {t(
                                        'templateGovernance.publishedVersionLabel',
                                    )}
                                </span>
                                <strong>
                                    {template.published_version
                                        ? `v${template.published_version.version_number}`
                                        : '—'}
                                </strong>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <span>
                                    {t('templateGovernance.openVersionLabel')}
                                </span>
                                <span className="flex items-center gap-2">
                                    {template.open_version ? (
                                        <>
                                            <strong>
                                                v
                                                {
                                                    template.open_version
                                                        .version_number
                                                }
                                            </strong>
                                            <TemplateGovernanceStatusBadge
                                                status={
                                                    template.open_version.status
                                                }
                                            />
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </span>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <span>
                                    {t('templateGovernance.templateLock')}
                                </span>
                                <Badge variant="outline">
                                    {template.lock_version}
                                </Badge>
                            </div>
                            {governanceCapabilities.submit &&
                                openVersion?.status === 'draft' && (
                                    <Button
                                        type="button"
                                        className="w-full"
                                        onClick={() =>
                                            setConfirmation('submit')
                                        }
                                    >
                                        <Send aria-hidden="true" />
                                        {t(
                                            'templateGovernance.submitForReview',
                                        )}
                                    </Button>
                                )}
                            {governanceCapabilities.publish &&
                                openVersion?.status === 'review' && (
                                    <Button
                                        type="button"
                                        className="w-full"
                                        onClick={() =>
                                            setConfirmation('publish')
                                        }
                                    >
                                        <CheckCircle2 aria-hidden="true" />
                                        {t('templateGovernance.publish')}
                                    </Button>
                                )}
                        </CardContent>
                    </Card>
                </div>

                {(governanceCapabilities.manage_roles ||
                    governanceCapabilities.assign_technical_owner) && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {governanceCapabilities.manage_roles && (
                            <ScopedDelegationsCard
                                template={template}
                                candidates={governanceRoleCandidates}
                            />
                        )}
                        {governanceCapabilities.assign_technical_owner && (
                            <TechnicalOwnerCard
                                template={template}
                                candidates={technicalOwnerCandidates}
                            />
                        )}
                    </div>
                )}

                <TemplateQualityCard
                    key={qualityVersion?.id ?? 'no-quality-version'}
                    templateSlug={template.slug}
                    version={qualityVersion}
                    quality={quality}
                    tenants={tenants}
                    defaultTenant={defaultTenant}
                    canRunValidation={
                        governanceCapabilities.run_quality_validation
                    }
                    canCaptureReference={
                        governanceCapabilities.capture_quality_reference
                    }
                />

                <TemplateCertificationCard
                    template={template}
                    canCertify={governanceCapabilities.certify}
                    canRevoke={governanceCapabilities.revoke_certification}
                />

                {governanceCapabilities.update_draft &&
                    openVersion?.status === 'draft' && (
                        <DraftEditor
                            key={`${openVersion.id}-${openVersion.lock_version}`}
                            template={template}
                            version={openVersion}
                            categories={categories}
                            candidates={businessOwnerCandidates}
                            ownerSearch={ownerSearch}
                            oracleResources={oracleResources}
                            canUpdateTechnicalDefinition={
                                governanceCapabilities.update_technical_definition
                            }
                        />
                    )}

                {template.open_version && openVersion === null && (
                    <Alert>
                        <FileClock aria-hidden="true" />
                        <AlertTitle>
                            {t('templateGovernance.openVersionNotLoaded')}
                        </AlertTitle>
                        <AlertDescription>
                            {t(
                                'templateGovernance.openVersionNotLoadedDescription',
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <TemplateVersionComparison
                    slug={template.slug}
                    versions={version_options}
                />

                <Card aria-busy={navigationPending}>
                    <CardHeader>
                        <CardTitle>
                            {t('templateGovernance.historyTitle')}
                        </CardTitle>
                        <CardDescription>
                            {t('templateGovernance.historyDescription')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {versions.data.length === 0 ? (
                            <div className="rounded-lg border border-dashed px-4 py-10 text-center">
                                <FileClock
                                    className="mx-auto mb-3 size-9 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <p className="font-medium">
                                    {t('templateGovernance.historyEmpty')}
                                </p>
                            </div>
                        ) : (
                            <ol className="space-y-3">
                                {versions.data.map((version) => (
                                    <li
                                        key={version.id}
                                        className="rounded-lg border p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="space-y-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <strong>
                                                        {t(
                                                            'templateGovernance.versionNumber',
                                                            {
                                                                version:
                                                                    version.version_number,
                                                            },
                                                        )}
                                                    </strong>
                                                    <TemplateGovernanceStatusBadge
                                                        status={version.status}
                                                    />
                                                </div>
                                                <p className="text-sm text-muted-foreground">
                                                    {version.change_summary ??
                                                        t(
                                                            'templateGovernance.noChangeSummary',
                                                        )}
                                                </p>
                                                {version.restored_from_version_id !==
                                                    null && (
                                                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                                        <RotateCcw
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {t(
                                                            'templateGovernance.restoredFromVersion',
                                                            {
                                                                version:
                                                                    version_options.find(
                                                                        (
                                                                            option,
                                                                        ) =>
                                                                            option.id ===
                                                                            version.restored_from_version_id,
                                                                    )
                                                                        ?.version_number ??
                                                                    version.restored_from_version_id,
                                                            },
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {governanceCapabilities.restore &&
                                                    version.published_at !==
                                                        null &&
                                                    version.status ===
                                                        'superseded' &&
                                                    version.id !==
                                                        template
                                                            .published_version
                                                            ?.id && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                setRestoreSummary(
                                                                    '',
                                                                );
                                                                setRestoreError(
                                                                    null,
                                                                );
                                                                setRestoreSource(
                                                                    version,
                                                                );
                                                            }}
                                                            disabled={
                                                                pending !==
                                                                    null ||
                                                                template.open_version !==
                                                                    null ||
                                                                template.governance_status ===
                                                                    'archived'
                                                            }
                                                            title={
                                                                template.open_version !==
                                                                null
                                                                    ? t(
                                                                          'templateGovernance.restoreUnavailableCycle',
                                                                      )
                                                                    : template.governance_status ===
                                                                        'archived'
                                                                      ? t(
                                                                            'templateGovernance.restoreUnavailableArchived',
                                                                        )
                                                                      : undefined
                                                            }
                                                        >
                                                            <RotateCcw aria-hidden="true" />
                                                            {t(
                                                                'templateGovernance.restoreAsDraft',
                                                            )}
                                                        </Button>
                                                    )}
                                                <Badge variant="outline">
                                                    <LockKeyhole aria-hidden="true" />
                                                    {version.lock_version}
                                                </Badge>
                                            </div>
                                        </div>
                                        <dl className="mt-4 grid gap-3 text-xs text-muted-foreground sm:grid-cols-3">
                                            <div>
                                                <dt className="font-medium text-foreground">
                                                    {t(
                                                        'templateGovernance.createdBy',
                                                    )}
                                                </dt>
                                                <dd>
                                                    {version.created_by?.name ??
                                                        '—'}
                                                    {version.created_at
                                                        ? ` · ${formatDate(
                                                              version.created_at,
                                                              {
                                                                  dateStyle:
                                                                      'medium',
                                                                  timeStyle:
                                                                      'short',
                                                              },
                                                          )}`
                                                        : ''}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="font-medium text-foreground">
                                                    {t(
                                                        'templateGovernance.submittedBy',
                                                    )}
                                                </dt>
                                                <dd>
                                                    {version.submitted_by
                                                        ?.name ?? '—'}
                                                    {version.submitted_at
                                                        ? ` · ${formatDate(
                                                              version.submitted_at,
                                                              {
                                                                  dateStyle:
                                                                      'medium',
                                                                  timeStyle:
                                                                      'short',
                                                              },
                                                          )}`
                                                        : ''}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="font-medium text-foreground">
                                                    {t(
                                                        'templateGovernance.publishedBy',
                                                    )}
                                                </dt>
                                                <dd>
                                                    {version.published_by
                                                        ?.name ?? '—'}
                                                    {version.published_at
                                                        ? ` · ${formatDate(
                                                              version.published_at,
                                                              {
                                                                  dateStyle:
                                                                      'medium',
                                                                  timeStyle:
                                                                      'short',
                                                              },
                                                          )}`
                                                        : ''}
                                                </dd>
                                            </div>
                                        </dl>
                                    </li>
                                ))}
                            </ol>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                            <span>
                                {t('templateGovernance.versionCount', {
                                    count: versions.total,
                                })}
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        loadVersionPage(
                                            versions.current_page - 1,
                                        )
                                    }
                                    disabled={
                                        navigationPending ||
                                        versions.current_page <= 1
                                    }
                                    aria-label={t('queries.previous')}
                                >
                                    <ChevronLeft aria-hidden="true" />
                                </Button>
                                <span>
                                    {t('queries.page')} {versions.current_page}{' '}
                                    / {versions.last_page}
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        loadVersionPage(
                                            versions.current_page + 1,
                                        )
                                    }
                                    disabled={
                                        navigationPending ||
                                        versions.current_page >=
                                            versions.last_page
                                    }
                                    aria-label={t('queries.next')}
                                >
                                    <ChevronRight aria-hidden="true" />
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Dialog
                open={restoreSource !== null}
                onOpenChange={(open) => {
                    if (!open && pending !== 'restore') {
                        setRestoreSource(null);
                        setRestoreError(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('templateGovernance.restoreConfirmTitle', {
                                version: restoreSource?.version_number ?? '',
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('templateGovernance.restoreConfirmDescription', {
                                version: restoreSource?.version_number ?? '',
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={restoreVersion} className="space-y-4">
                        {restoreError && (
                            <Alert variant="destructive" aria-live="assertive">
                                <CircleHelp aria-hidden="true" />
                                <AlertTitle>
                                    {t('templateGovernance.restoreErrorTitle')}
                                </AlertTitle>
                                <AlertDescription className="space-y-3">
                                    <p>{restoreError}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            setRestoreSource(null);
                                            setRestoreError(null);
                                            router.reload();
                                        }}
                                    >
                                        <RefreshCw aria-hidden="true" />
                                        {t('templateGovernance.reloadState')}
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}
                        <Alert>
                            <RotateCcw aria-hidden="true" />
                            <AlertTitle>
                                {t('templateGovernance.restoreCreatesDraft')}
                            </AlertTitle>
                            <AlertDescription>
                                {t(
                                    'templateGovernance.restorePreservesHistory',
                                )}
                            </AlertDescription>
                        </Alert>
                        <div className="grid gap-2">
                            <Label htmlFor="restore-change-summary">
                                {t('templateGovernance.changeSummaryLabel')}
                            </Label>
                            <Textarea
                                id="restore-change-summary"
                                value={restoreSummary}
                                onChange={(event) =>
                                    setRestoreSummary(event.target.value)
                                }
                                maxLength={2000}
                                rows={4}
                                autoFocus
                                disabled={pending === 'restore'}
                                placeholder={t(
                                    'templateGovernance.restoreSummaryPlaceholder',
                                    {
                                        version:
                                            restoreSource?.version_number ?? '',
                                    },
                                )}
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRestoreSource(null)}
                                disabled={pending === 'restore'}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={pending === 'restore'}
                            >
                                {pending === 'restore' && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                <RotateCcw aria-hidden="true" />
                                {t('templateGovernance.restoreAsDraft')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={createDraftOpen}
                onOpenChange={(open) =>
                    pending === null && setCreateDraftOpen(open)
                }
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('templateGovernance.createDraftTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('templateGovernance.createDraftDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={createDraft} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="new-draft-summary">
                                {t('templateGovernance.changeSummaryLabel')}
                            </Label>
                            <Textarea
                                id="new-draft-summary"
                                value={draftSummary}
                                onChange={(event) =>
                                    setDraftSummary(event.target.value)
                                }
                                maxLength={2000}
                                rows={4}
                                autoFocus
                                disabled={pending === 'create'}
                                placeholder={t(
                                    'templateGovernance.changeSummaryPlaceholder',
                                )}
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCreateDraftOpen(false)}
                                disabled={pending === 'create'}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={pending === 'create'}
                            >
                                {pending === 'create' && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                {t('templateGovernance.createDraft')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={confirmation !== null}
                onOpenChange={(open) =>
                    !open && pending === null && setConfirmation(null)
                }
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirmationTitle}</DialogTitle>
                        <DialogDescription>
                            {confirmationDescription}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmation(null)}
                            disabled={pending !== null}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant={
                                confirmation === 'archive'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={confirmAction}
                            disabled={pending !== null}
                        >
                            {pending !== null && (
                                <Spinner aria-label={t('common.loading')} />
                            )}
                            {confirmation === 'submit'
                                ? t('templateGovernance.submitForReview')
                                : confirmation === 'publish'
                                  ? t('templateGovernance.publish')
                                  : t('templateGovernance.archive')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

QueryTemplateGovernanceShow.layout = {
    breadcrumbs: [
        {
            title: 'Gouvernance des modèles',
            href: governanceIndexUrl,
        },
        { title: 'Détail', href: '#' },
    ],
};
