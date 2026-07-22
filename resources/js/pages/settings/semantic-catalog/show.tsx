import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpenText,
    Database,
    GitBranch,
    Pencil,
    Plus,
    ShieldCheck,
    TableProperties,
    UserRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { DataTable } from '@/components/data-table';
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
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import semanticCatalog from '@/routes/semantic-catalog';
import type {
    SemanticCardinality,
    SemanticCatalogField,
    SemanticCatalogRelation,
    SemanticCatalogShowProps,
    SemanticClassification,
    SemanticDataCategory,
    SemanticFieldTranslation,
    SemanticLocale,
    SemanticRelationKind,
    SemanticRelationStatus,
    SemanticRelationTranslation,
    SemanticResourceTranslation,
    SemanticSqlMappingStatus,
} from '@/types';

const LOCALES: SemanticLocale[] = ['fr', 'en', 'es'];

const CLASSIFICATIONS: SemanticClassification[] = [
    'unclassified',
    'public',
    'internal',
    'confidential',
    'restricted',
];

const DATA_CATEGORIES: SemanticDataCategory[] = [
    'general',
    'personal',
    'financial',
    'hr',
    'credential',
    'operational',
];

const CREATABLE_RELATION_KINDS: SemanticRelationKind[] = ['expand', 'join'];

const RELATION_STATUSES: SemanticRelationStatus[] = [
    'draft',
    'published',
    'deprecated',
];

const CARDINALITIES: SemanticCardinality[] = [
    'one_to_one',
    'one_to_many',
    'many_to_one',
    'many_to_many',
];

type Section = 'overview' | 'fields' | 'relations';

function listFromText(value: string): string[] {
    return value
        .split(/[,\n]/)
        .map((item) => item.trim())
        .filter(
            (item, index, values) =>
                item !== '' && values.indexOf(item) === index,
        );
}

function textFromList(values: string[]): string {
    return values.join('\n');
}

function translationFor<T extends { locale: SemanticLocale }>(
    translations: T[],
    locale: SemanticLocale,
): T | undefined {
    return (
        translations.find((translation) => translation.locale === locale) ??
        translations.find((translation) => translation.locale === 'fr')
    );
}

function classificationLabel(
    classification: SemanticClassification,
    t: ReturnType<typeof useI18n>['t'],
): string {
    return t(`semanticCatalog.classification.${classification}`);
}

function dataCategoryLabel(
    category: SemanticDataCategory,
    t: ReturnType<typeof useI18n>['t'],
): string {
    return t(`semanticCatalog.dataCategory.${category}`);
}

function mappingStatusLabel(
    status: SemanticSqlMappingStatus,
    t: ReturnType<typeof useI18n>['t'],
): string {
    return t(`semanticCatalog.mappingStatus.${status}`);
}

function MappingStatusBadge({ status }: { status: SemanticSqlMappingStatus }) {
    const { t } = useI18n();
    const variant =
        status === 'exact'
            ? 'secondary'
            : status === 'unsupported'
              ? 'destructive'
              : 'outline';

    return <Badge variant={variant}>{mappingStatusLabel(status, t)}</Badge>;
}

type ResourceTranslationDraft = Omit<SemanticResourceTranslation, 'locale'>;

function ResourceEditor({
    resource,
    ownerCandidates,
}: Pick<SemanticCatalogShowProps, 'resource' | 'ownerCandidates'>) {
    const { t } = useI18n();
    const initialTranslations = Object.fromEntries(
        LOCALES.map((locale) => {
            const translation = resource.translations.find(
                (item) => item.locale === locale,
            );

            return [
                locale,
                {
                    name: translation?.name ?? '',
                    description: translation?.description ?? '',
                    synonyms: translation?.synonyms ?? [],
                    examples: translation?.examples ?? [],
                },
            ];
        }),
    ) as Record<SemanticLocale, ResourceTranslationDraft>;
    const [draft, setDraft] = useState({
        business_owner_user_id: resource.business_owner
            ? String(resource.business_owner.id)
            : 'none',
        technical_owner_user_id: resource.technical_owner
            ? String(resource.technical_owner.id)
            : 'none',
        classification: resource.classification,
        data_category: resource.data_category,
        mapping_notes: resource.mapping_notes ?? '',
        is_active: resource.is_active,
        translations: initialTranslations,
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    function updateTranslation(
        locale: SemanticLocale,
        key: keyof ResourceTranslationDraft,
        value: string | string[],
    ) {
        setDraft((current) => ({
            ...current,
            translations: {
                ...current.translations,
                [locale]: {
                    ...current.translations[locale],
                    [key]: value,
                },
            },
        }));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setProcessing(true);
        setErrors({});
        router.patch(
            semanticCatalog.update.url(resource.resource_key),
            {
                business_owner_user_id:
                    draft.business_owner_user_id === 'none'
                        ? null
                        : Number(draft.business_owner_user_id),
                technical_owner_user_id:
                    draft.technical_owner_user_id === 'none'
                        ? null
                        : Number(draft.technical_owner_user_id),
                classification: draft.classification,
                data_category: draft.data_category,
                mapping_notes: draft.mapping_notes || null,
                is_active: draft.is_active,
                lock_version: resource.lock_version,
                translations: Object.fromEntries(
                    LOCALES.map((locale) => [
                        locale,
                        {
                            name: draft.translations[locale].name,
                            description:
                                draft.translations[locale].description || null,
                            synonyms: draft.translations[locale].synonyms,
                            examples: draft.translations[locale].examples,
                        },
                    ]),
                ),
            },
            {
                preserveScroll: true,
                onError: (nextErrors) => setErrors(nextErrors),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <form className="space-y-6" onSubmit={submit}>
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div className="grid gap-2">
                    <Label>{t('semanticCatalog.businessOwner')}</Label>
                    <Select
                        value={draft.business_owner_user_id}
                        onValueChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                business_owner_user_id: value,
                            }))
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">
                                {t('semanticCatalog.ownerUnassigned')}
                            </SelectItem>
                            {ownerCandidates.map((candidate) => (
                                <SelectItem
                                    key={candidate.id}
                                    value={String(candidate.id)}
                                >
                                    {candidate.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.business_owner_user_id} />
                </div>
                <div className="grid gap-2">
                    <Label>{t('semanticCatalog.technicalOwner')}</Label>
                    <Select
                        value={draft.technical_owner_user_id}
                        onValueChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                technical_owner_user_id: value,
                            }))
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">
                                {t('semanticCatalog.ownerUnassigned')}
                            </SelectItem>
                            {ownerCandidates.map((candidate) => (
                                <SelectItem
                                    key={candidate.id}
                                    value={String(candidate.id)}
                                >
                                    {candidate.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.technical_owner_user_id} />
                </div>
                <div className="grid gap-2">
                    <Label>{t('semanticCatalog.classification')}</Label>
                    <Select
                        value={draft.classification}
                        onValueChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                classification: value as SemanticClassification,
                            }))
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CLASSIFICATIONS.map((classification) => (
                                <SelectItem
                                    key={classification}
                                    value={classification}
                                >
                                    {classificationLabel(classification, t)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.classification} />
                </div>
                <div className="grid gap-2">
                    <Label>{t('semanticCatalog.dataCategory')}</Label>
                    <Select
                        value={draft.data_category}
                        onValueChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                data_category: value as SemanticDataCategory,
                            }))
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {DATA_CATEGORIES.map((category) => (
                                <SelectItem key={category} value={category}>
                                    {dataCategoryLabel(category, t)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.data_category} />
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-3">
                {LOCALES.map((locale) => (
                    <section
                        key={locale}
                        className="space-y-4 rounded-lg border p-4"
                    >
                        <h3 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                            {t(`semanticCatalog.locale.${locale}`)}
                        </h3>
                        <div className="grid gap-2">
                            <Label htmlFor={`resource-${locale}-name`}>
                                {t('semanticCatalog.name')}
                            </Label>
                            <Input
                                id={`resource-${locale}-name`}
                                value={draft.translations[locale].name}
                                onChange={(event) =>
                                    updateTranslation(
                                        locale,
                                        'name',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            <InputError
                                message={errors[`translations.${locale}.name`]}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`resource-${locale}-description`}>
                                {t('semanticCatalog.definition')}
                            </Label>
                            <Textarea
                                id={`resource-${locale}-description`}
                                value={
                                    draft.translations[locale].description ?? ''
                                }
                                onChange={(event) =>
                                    updateTranslation(
                                        locale,
                                        'description',
                                        event.target.value,
                                    )
                                }
                                rows={3}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`resource-${locale}-synonyms`}>
                                {t('semanticCatalog.synonyms')}
                            </Label>
                            <Textarea
                                id={`resource-${locale}-synonyms`}
                                value={textFromList(
                                    draft.translations[locale].synonyms,
                                )}
                                onChange={(event) =>
                                    updateTranslation(
                                        locale,
                                        'synonyms',
                                        listFromText(event.target.value),
                                    )
                                }
                                rows={2}
                                placeholder={t(
                                    'semanticCatalog.oneValuePerLine',
                                )}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`resource-${locale}-examples`}>
                                {t('semanticCatalog.examples')}
                            </Label>
                            <Textarea
                                id={`resource-${locale}-examples`}
                                value={textFromList(
                                    draft.translations[locale].examples,
                                )}
                                onChange={(event) =>
                                    updateTranslation(
                                        locale,
                                        'examples',
                                        listFromText(event.target.value),
                                    )
                                }
                                rows={2}
                                placeholder={t(
                                    'semanticCatalog.oneValuePerLine',
                                )}
                            />
                        </div>
                    </section>
                ))}
            </div>

            <section className="space-y-4 rounded-lg border p-4">
                <div>
                    <h3 className="font-medium">
                        {t('semanticCatalog.sqlMappingTitle')}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {t('semanticCatalog.sqlMappingDescription')}
                    </p>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg border bg-muted/20 p-3">
                        <p className="text-xs text-muted-foreground">
                            {t('semanticCatalog.sqlTable')}
                        </p>
                        <code className="mt-1 block text-xs">
                            {[resource.sql_table, resource.sql_alias]
                                .filter(Boolean)
                                .join(' · ') || '—'}
                        </code>
                    </div>
                    <div className="rounded-lg border bg-muted/20 p-3">
                        <p className="text-xs text-muted-foreground">
                            {t('semanticCatalog.mappingStatus')}
                        </p>
                        <div className="mt-1">
                            <MappingStatusBadge
                                status={resource.sql_mapping_status}
                            />
                        </div>
                    </div>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="resource-mapping-notes">
                        {t('semanticCatalog.mappingNotes')}
                    </Label>
                    <Textarea
                        id="resource-mapping-notes"
                        value={draft.mapping_notes}
                        onChange={(event) =>
                            setDraft((current) => ({
                                ...current,
                                mapping_notes: event.target.value,
                            }))
                        }
                        rows={3}
                    />
                </div>
            </section>

            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="resource-active"
                        checked={draft.is_active}
                        onCheckedChange={(checked) =>
                            setDraft((current) => ({
                                ...current,
                                is_active: checked === true,
                            }))
                        }
                    />
                    <Label htmlFor="resource-active" className="font-normal">
                        {t('semanticCatalog.activeResource')}
                    </Label>
                </div>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner aria-hidden="true" />}
                    {processing ? t('common.saving') : t('common.save')}
                </Button>
            </div>
            <InputError
                message={errors.semantic_catalog ?? Object.values(errors)[0]}
            />
        </form>
    );
}

type FieldTranslationDraft = Omit<SemanticFieldTranslation, 'locale'>;

function FieldDialog({
    resourceKey,
    field,
}: {
    resourceKey: string;
    field: SemanticCatalogField;
}) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const initialTranslations = Object.fromEntries(
        LOCALES.map((locale) => {
            const translation = field.translations.find(
                (item) => item.locale === locale,
            );

            return [
                locale,
                {
                    name: translation?.name ?? '',
                    description: translation?.description ?? '',
                    synonyms: translation?.synonyms ?? [],
                    examples: translation?.examples ?? [],
                },
            ];
        }),
    ) as Record<SemanticLocale, FieldTranslationDraft>;
    const [draft, setDraft] = useState({
        classification: field.classification,
        data_category: field.data_category,
        is_active: field.is_active,
        translations: initialTranslations,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setProcessing(true);
        setErrors({});
        router.patch(
            semanticCatalog.fields.update.url([resourceKey, field.id]),
            {
                lock_version: field.lock_version,
                classification: draft.classification,
                data_category: draft.data_category,
                is_active: draft.is_active,
                translations: Object.fromEntries(
                    LOCALES.map((locale) => [
                        locale,
                        {
                            ...draft.translations[locale],
                            description:
                                draft.translations[locale].description || null,
                        },
                    ]),
                ),
            },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onError: (nextErrors) => setErrors(nextErrors),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label={t('semanticCatalog.fieldEditFor', {
                        field: field.source_name,
                    })}
                >
                    <Pencil aria-hidden="true" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('semanticCatalog.fieldEditTitle', {
                            field: field.source_name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('semanticCatalog.fieldEditDescription')}
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-6" onSubmit={submit}>
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.classification')}</Label>
                            <Select
                                value={draft.classification}
                                onValueChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        classification:
                                            value as SemanticClassification,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CLASSIFICATIONS.map((classification) => (
                                        <SelectItem
                                            key={classification}
                                            value={classification}
                                        >
                                            {classificationLabel(
                                                classification,
                                                t,
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.dataCategory')}</Label>
                            <Select
                                value={draft.data_category}
                                onValueChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        data_category:
                                            value as SemanticDataCategory,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {DATA_CATEGORIES.map((category) => (
                                        <SelectItem
                                            key={category}
                                            value={category}
                                        >
                                            {dataCategoryLabel(category, t)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="grid gap-4 xl:grid-cols-3">
                        {LOCALES.map((locale) => (
                            <section
                                key={locale}
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <h3 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t(`semanticCatalog.locale.${locale}`)}
                                </h3>
                                <div className="grid gap-2">
                                    <Label>{t('semanticCatalog.name')}</Label>
                                    <Input
                                        value={draft.translations[locale].name}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                translations: {
                                                    ...current.translations,
                                                    [locale]: {
                                                        ...current.translations[
                                                            locale
                                                        ],
                                                        name: event.target
                                                            .value,
                                                    },
                                                },
                                            }))
                                        }
                                        required
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        {t('semanticCatalog.definition')}
                                    </Label>
                                    <Textarea
                                        value={
                                            draft.translations[locale]
                                                .description ?? ''
                                        }
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                translations: {
                                                    ...current.translations,
                                                    [locale]: {
                                                        ...current.translations[
                                                            locale
                                                        ],
                                                        description:
                                                            event.target.value,
                                                    },
                                                },
                                            }))
                                        }
                                        rows={3}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        {t('semanticCatalog.synonyms')}
                                    </Label>
                                    <Textarea
                                        value={textFromList(
                                            draft.translations[locale].synonyms,
                                        )}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                translations: {
                                                    ...current.translations,
                                                    [locale]: {
                                                        ...current.translations[
                                                            locale
                                                        ],
                                                        synonyms: listFromText(
                                                            event.target.value,
                                                        ),
                                                    },
                                                },
                                            }))
                                        }
                                        rows={2}
                                        placeholder={t(
                                            'semanticCatalog.oneValuePerLine',
                                        )}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        {t('semanticCatalog.examples')}
                                    </Label>
                                    <Textarea
                                        value={textFromList(
                                            draft.translations[locale].examples,
                                        )}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                translations: {
                                                    ...current.translations,
                                                    [locale]: {
                                                        ...current.translations[
                                                            locale
                                                        ],
                                                        examples: listFromText(
                                                            event.target.value,
                                                        ),
                                                    },
                                                },
                                            }))
                                        }
                                        rows={2}
                                        placeholder={t(
                                            'semanticCatalog.oneValuePerLine',
                                        )}
                                    />
                                </div>
                            </section>
                        ))}
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`semantic-field-active-${field.id}`}
                            checked={draft.is_active}
                            onCheckedChange={(checked) =>
                                setDraft((current) => ({
                                    ...current,
                                    is_active: checked === true,
                                }))
                            }
                        />
                        <Label
                            htmlFor={`semantic-field-active-${field.id}`}
                            className="font-normal"
                        >
                            {t('semanticCatalog.activeField')}
                        </Label>
                    </div>
                    <InputError
                        message={errors.field ?? Object.values(errors)[0]}
                    />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner aria-hidden="true" />}
                            {processing ? t('common.saving') : t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type RelationTranslationDraft = Omit<SemanticRelationTranslation, 'locale'>;

function RelationDialog({
    resourceKey,
    targetResources,
    childResources,
    relation,
}: {
    resourceKey: string;
    targetResources: SemanticCatalogShowProps['targetResources'];
    childResources: SemanticCatalogShowProps['childResources'];
    relation?: SemanticCatalogRelation;
}) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const initialTranslations = Object.fromEntries(
        LOCALES.map((locale) => {
            const translation = relation?.translations.find(
                (item) => item.locale === locale,
            );

            return [
                locale,
                {
                    name: translation?.name ?? '',
                    description: translation?.description ?? '',
                },
            ];
        }),
    ) as Record<SemanticLocale, RelationTranslationDraft>;
    const initialKind =
        relation?.kind ??
        (targetResources.length > 0
            ? 'join'
            : ('expand' as SemanticRelationKind));
    const initialJoinTarget = targetResources[0];
    const [draft, setDraft] = useState({
        relation_key: relation?.relation_key ?? '',
        kind: initialKind,
        target_key:
            relation?.target_key ??
            (initialKind === 'join'
                ? (initialJoinTarget?.resource_key ?? '')
                : (childResources[0]?.key ?? '')),
        source_field:
            relation?.source_field ??
            (initialKind === 'join'
                ? (initialJoinTarget?.local_key ?? '')
                : ''),
        target_field:
            relation?.target_field ??
            (initialKind === 'join'
                ? (initialJoinTarget?.remote_key ?? '')
                : ''),
        cardinality:
            relation?.cardinality ?? ('many_to_one' as SemanticCardinality),
        status: relation?.status ?? ('draft' as SemanticRelationStatus),
        translations: initialTranslations,
    });
    const targetResource = targetResources.find(
        (resource) => resource.resource_key === draft.target_key,
    );
    const creatableRelationKinds = CREATABLE_RELATION_KINDS.filter((kind) =>
        kind === 'join'
            ? targetResources.length > 0
            : childResources.length > 0,
    );
    const createTargetIncomplete =
        !relation &&
        (draft.target_key.trim() === '' ||
            (draft.kind === 'join' &&
                (!targetResource ||
                    draft.source_field !== targetResource.local_key ||
                    draft.target_field !== targetResource.remote_key)) ||
            (draft.kind === 'expand' &&
                !childResources.some(
                    (child) => child.key === draft.target_key,
                )));

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setProcessing(true);
        setErrors({});
        const translations = Object.fromEntries(
            LOCALES.map((locale) => [
                locale,
                {
                    name: draft.translations[locale].name,
                    description: draft.translations[locale].description || null,
                },
            ]),
        );
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: (nextErrors: Record<string, string>) =>
                setErrors(nextErrors),
            onFinish: () => setProcessing(false),
        };

        if (relation) {
            router.patch(
                semanticCatalog.relations.update.url([
                    resourceKey,
                    relation.id,
                ]),
                {
                    lock_version: relation.lock_version,
                    cardinality: draft.cardinality,
                    status: draft.status,
                    translations,
                },
                options,
            );
        } else {
            router.post(
                semanticCatalog.relations.store.url(resourceKey),
                {
                    ...(draft.kind === 'join'
                        ? { target_resource_id: targetResource?.id }
                        : {}),
                    relation_key: draft.relation_key,
                    kind: draft.kind,
                    target_key: draft.target_key,
                    source_field: draft.source_field || null,
                    target_field: draft.target_field || null,
                    cardinality: draft.cardinality,
                    translations,
                },
                options,
            );
        }
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {relation ? (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={t('semanticCatalog.relationEditFor', {
                            relation: relation.relation_key,
                        })}
                    >
                        <Pencil aria-hidden="true" />
                    </Button>
                ) : (
                    <Button
                        type="button"
                        disabled={creatableRelationKinds.length === 0}
                    >
                        <Plus aria-hidden="true" />
                        {t('semanticCatalog.relationCreate')}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {relation
                            ? t('semanticCatalog.relationEditTitle')
                            : t('semanticCatalog.relationCreateTitle')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('semanticCatalog.relationFormDescription')}
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-6" onSubmit={submit}>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.relationKey')}</Label>
                            <Input
                                value={draft.relation_key}
                                onChange={(event) =>
                                    setDraft((current) => ({
                                        ...current,
                                        relation_key: event.target.value,
                                    }))
                                }
                                disabled={Boolean(relation)}
                                required
                            />
                            <InputError message={errors.relation_key} />
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.relationKind')}</Label>
                            <Select
                                value={draft.kind}
                                onValueChange={(value) => {
                                    const kind = value as SemanticRelationKind;
                                    const joinTarget = targetResources[0];

                                    setDraft((current) => ({
                                        ...current,
                                        kind,
                                        target_key:
                                            kind === 'join'
                                                ? (joinTarget?.resource_key ??
                                                  '')
                                                : (childResources[0]?.key ??
                                                  ''),
                                        source_field:
                                            kind === 'join'
                                                ? (joinTarget?.local_key ?? '')
                                                : '',
                                        target_field:
                                            kind === 'join'
                                                ? (joinTarget?.remote_key ?? '')
                                                : '',
                                    }));
                                }}
                                disabled={Boolean(relation)}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(relation
                                        ? [relation.kind]
                                        : creatableRelationKinds
                                    ).map((kind) => (
                                        <SelectItem key={kind} value={kind}>
                                            {t(
                                                `semanticCatalog.relationKind.${kind}`,
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.targetResource')}</Label>
                            {draft.kind === 'join' ? (
                                <Select
                                    value={draft.target_key}
                                    onValueChange={(value) => {
                                        const selectedTarget =
                                            targetResources.find(
                                                (resource) =>
                                                    resource.resource_key ===
                                                    value,
                                            );

                                        setDraft((current) => ({
                                            ...current,
                                            target_key: value,
                                            source_field:
                                                selectedTarget?.local_key ?? '',
                                            target_field:
                                                selectedTarget?.remote_key ??
                                                '',
                                        }));
                                    }}
                                    disabled={Boolean(relation)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {targetResources.map((resource) => (
                                            <SelectItem
                                                key={resource.resource_key}
                                                value={resource.resource_key}
                                            >
                                                {resource.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : draft.kind === 'expand' ? (
                                <Select
                                    value={draft.target_key}
                                    onValueChange={(value) =>
                                        setDraft((current) => ({
                                            ...current,
                                            target_key: value,
                                        }))
                                    }
                                    disabled={Boolean(relation)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {childResources.map((child) => (
                                            <SelectItem
                                                key={child.key}
                                                value={child.key}
                                            >
                                                {child.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : (
                                <Input value={draft.target_key} readOnly />
                            )}
                            <InputError message={errors.target_resource_id} />
                            <InputError message={errors.target_key} />
                        </div>
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.cardinality')}</Label>
                            <Select
                                value={draft.cardinality}
                                onValueChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        cardinality:
                                            value as SemanticCardinality,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {CARDINALITIES.map((cardinality) => (
                                        <SelectItem
                                            key={cardinality}
                                            value={cardinality}
                                        >
                                            {t(
                                                `semanticCatalog.cardinality.${cardinality}`,
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {draft.kind === 'join' && (
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>
                                    {t('semanticCatalog.sourceField')}
                                </Label>
                                <Input
                                    value={draft.source_field}
                                    readOnly
                                    className="font-mono text-xs"
                                />
                                <InputError message={errors.source_field} />
                            </div>
                            <div className="grid gap-2">
                                <Label>
                                    {t('semanticCatalog.targetField')}
                                </Label>
                                <Input
                                    value={draft.target_field}
                                    readOnly
                                    className="font-mono text-xs"
                                />
                                <InputError message={errors.target_field} />
                            </div>
                        </div>
                    )}
                    {relation && (
                        <div className="grid gap-2">
                            <Label>{t('semanticCatalog.relationStatus')}</Label>
                            <Select
                                value={draft.status}
                                onValueChange={(value) =>
                                    setDraft((current) => ({
                                        ...current,
                                        status: value as SemanticRelationStatus,
                                    }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {RELATION_STATUSES.map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {t(
                                                `semanticCatalog.relationStatus.${status}`,
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="grid gap-4 xl:grid-cols-3">
                        {LOCALES.map((locale) => (
                            <section
                                key={locale}
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <h3 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    {t(`semanticCatalog.locale.${locale}`)}
                                </h3>
                                <div className="grid gap-2">
                                    <Label>
                                        {t('semanticCatalog.relationLabel')}
                                    </Label>
                                    <Input
                                        value={draft.translations[locale].name}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                translations: {
                                                    ...current.translations,
                                                    [locale]: {
                                                        ...current.translations[
                                                            locale
                                                        ],
                                                        name: event.target
                                                            .value,
                                                    },
                                                },
                                            }))
                                        }
                                        required
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        {t('semanticCatalog.definition')}
                                    </Label>
                                    <Textarea
                                        value={
                                            draft.translations[locale]
                                                .description ?? ''
                                        }
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                translations: {
                                                    ...current.translations,
                                                    [locale]: {
                                                        ...current.translations[
                                                            locale
                                                        ],
                                                        description:
                                                            event.target.value,
                                                    },
                                                },
                                            }))
                                        }
                                        rows={3}
                                    />
                                </div>
                            </section>
                        ))}
                    </div>
                    <InputError
                        message={errors.relation ?? Object.values(errors)[0]}
                    />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing || createTargetIncomplete}
                        >
                            {processing && <Spinner aria-hidden="true" />}
                            {processing ? t('common.saving') : t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SemanticCatalogShow({
    resource,
    ownerCandidates,
    targetResources,
    childResources,
    capabilities,
}: SemanticCatalogShowProps) {
    const { t, locale, formatDate } = useI18n();
    const [section, setSection] = useState<Section>('overview');
    const localizedResource = translationFor(resource.translations, locale);
    const resourceName = localizedResource?.name || resource.source_name;

    const fieldColumns = useMemo<DataTableColumn<SemanticCatalogField>[]>(
        () => [
            {
                key: 'field',
                header: t('semanticCatalog.field'),
                icon: TableProperties,
                cell: (field) => {
                    const translation = translationFor(
                        field.translations,
                        locale,
                    );

                    return (
                        <div className="max-w-sm space-y-1 whitespace-normal">
                            <p className="font-medium">
                                {translation?.name || field.source_name}
                            </p>
                            <code className="block text-xs text-muted-foreground">
                                {field.child_key
                                    ? `${field.child_key}.${field.source_name}`
                                    : field.source_name}
                            </code>
                        </div>
                    );
                },
            },
            {
                key: 'type',
                header: t('semanticCatalog.dataType'),
                cell: (field) => field.data_type ?? '—',
            },
            {
                key: 'classification',
                header: t('semanticCatalog.classification'),
                icon: ShieldCheck,
                cell: (field) => (
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">
                            {classificationLabel(field.classification, t)}
                        </Badge>
                        <Badge variant="outline">
                            {dataCategoryLabel(field.data_category, t)}
                        </Badge>
                    </div>
                ),
            },
            {
                key: 'mapping',
                header: t('semanticCatalog.sqlMapping'),
                cell: (field) => (
                    <div className="max-w-xs space-y-1 whitespace-normal">
                        <MappingStatusBadge status={field.sql_mapping_status} />
                        {field.sql_expression && (
                            <code className="block text-xs break-all text-muted-foreground">
                                {field.sql_expression}
                            </code>
                        )}
                    </div>
                ),
            },
            {
                key: 'properties',
                header: t('semanticCatalog.properties'),
                cell: (field) => (
                    <div className="flex flex-wrap gap-1">
                        {field.is_nullable && (
                            <Badge variant="outline">
                                {t('semanticCatalog.nullable')}
                            </Badge>
                        )}
                        {field.is_updatable && (
                            <Badge variant="outline">
                                {t('semanticCatalog.updatable')}
                            </Badge>
                        )}
                        {!field.is_active && (
                            <Badge variant="destructive">
                                {t('common.inactive')}
                            </Badge>
                        )}
                    </div>
                ),
            },
            ...(capabilities.update_fields
                ? [
                      {
                          key: 'actions',
                          header: t('common.actions'),
                          align: 'right' as const,
                          cell: (field: SemanticCatalogField) => (
                              <FieldDialog
                                  resourceKey={resource.resource_key}
                                  field={field}
                              />
                          ),
                      },
                  ]
                : []),
        ],
        [capabilities.update_fields, locale, resource.resource_key, t],
    );

    const relationColumns = useMemo<DataTableColumn<SemanticCatalogRelation>[]>(
        () => [
            {
                key: 'relation',
                header: t('semanticCatalog.relation'),
                icon: GitBranch,
                cell: (relation) => {
                    const translation = translationFor(
                        relation.translations,
                        locale,
                    );

                    return (
                        <div className="max-w-sm space-y-1 whitespace-normal">
                            <p className="font-medium">
                                {translation?.name || relation.relation_key}
                            </p>
                            <code className="block text-xs text-muted-foreground">
                                {relation.relation_key}
                            </code>
                        </div>
                    );
                },
            },
            {
                key: 'target',
                header: t('semanticCatalog.targetResource'),
                cell: (relation) => (
                    <div className="space-y-1">
                        <code className="text-xs">{relation.target_key}</code>
                        <p className="text-xs text-muted-foreground">
                            {relation.source_field} → {relation.target_field}
                        </p>
                    </div>
                ),
            },
            {
                key: 'kind',
                header: t('semanticCatalog.relationKind'),
                cell: (relation) => (
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">
                            {t(`semanticCatalog.relationKind.${relation.kind}`)}
                        </Badge>
                        <Badge variant="outline">
                            {t(
                                `semanticCatalog.cardinality.${relation.cardinality}`,
                            )}
                        </Badge>
                    </div>
                ),
            },
            {
                key: 'sql',
                header: t('semanticCatalog.sqlMapping'),
                cell: (relation) => (
                    <div className="max-w-sm space-y-1 whitespace-normal">
                        <code className="block text-xs">
                            {[relation.sql_table, relation.sql_alias]
                                .filter(Boolean)
                                .join(' · ') || '—'}
                        </code>
                        {relation.sql_join && (
                            <p className="line-clamp-2 font-mono text-xs text-muted-foreground">
                                {relation.sql_join}
                            </p>
                        )}
                    </div>
                ),
            },
            {
                key: 'status',
                header: t('semanticCatalog.status'),
                cell: (relation) => (
                    <Badge
                        variant={
                            relation.status === 'published'
                                ? 'secondary'
                                : relation.status === 'deprecated'
                                  ? 'destructive'
                                  : 'outline'
                        }
                    >
                        {t(`semanticCatalog.relationStatus.${relation.status}`)}
                    </Badge>
                ),
            },
            ...(capabilities.manage_relations
                ? [
                      {
                          key: 'actions',
                          header: t('common.actions'),
                          align: 'right' as const,
                          cell: (relation: SemanticCatalogRelation) => (
                              <RelationDialog
                                  resourceKey={resource.resource_key}
                                  targetResources={targetResources}
                                  childResources={childResources}
                                  relation={relation}
                              />
                          ),
                      },
                  ]
                : []),
        ],
        [
            capabilities.manage_relations,
            childResources,
            locale,
            resource.resource_key,
            t,
            targetResources,
        ],
    );

    const sections: Array<{
        key: Section;
        label: string;
        icon: typeof Database;
        count?: number;
    }> = [
        {
            key: 'overview',
            label: t('semanticCatalog.overviewTab'),
            icon: Database,
        },
        {
            key: 'fields',
            label: t('semanticCatalog.fieldsTab'),
            icon: TableProperties,
            count: resource.fields.length,
        },
        {
            key: 'relations',
            label: t('semanticCatalog.relationsTab'),
            icon: GitBranch,
            count: resource.relations.length,
        },
    ];

    return (
        <>
            <Head title={resourceName} />
            <h1 className="sr-only">{resourceName}</h1>

            <div className="space-y-5">
                <div>
                    <Button asChild size="sm" variant="ghost" className="mb-3">
                        <Link href={semanticCatalog.index()}>
                            <ArrowLeft aria-hidden="true" />
                            {t('semanticCatalog.backToCatalog')}
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={resourceName}
                        description={t('semanticCatalog.resourceDescription', {
                            key: resource.resource_key,
                        })}
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card className="gap-3 py-4">
                        <CardContent className="space-y-1 px-4">
                            <p className="text-xs text-muted-foreground">
                                {t('semanticCatalog.domain')}
                            </p>
                            <p className="font-medium">{resource.domain}</p>
                        </CardContent>
                    </Card>
                    <Card className="gap-3 py-4">
                        <CardContent className="space-y-1 px-4">
                            <p className="text-xs text-muted-foreground">
                                {t('semanticCatalog.classification')}
                            </p>
                            <p className="font-medium">
                                {classificationLabel(
                                    resource.classification,
                                    t,
                                )}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="gap-3 py-4">
                        <CardContent className="space-y-1 px-4">
                            <p className="text-xs text-muted-foreground">
                                {t('semanticCatalog.fieldsTab')}
                            </p>
                            <p className="text-2xl font-semibold">
                                {resource.fields.length}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="gap-3 py-4">
                        <CardContent className="space-y-1 px-4">
                            <p className="text-xs text-muted-foreground">
                                {t('semanticCatalog.mappingStatus')}
                            </p>
                            <MappingStatusBadge
                                status={resource.sql_mapping_status}
                            />
                        </CardContent>
                    </Card>
                </div>

                {resource.sql_mapping_status === 'unsupported' && (
                    <Alert>
                        <AlertTriangle aria-hidden="true" />
                        <AlertTitle>
                            {t('semanticCatalog.sqlUnavailable')}
                        </AlertTitle>
                        <AlertDescription>
                            {t('semanticCatalog.sqlUnavailableDescription')}
                        </AlertDescription>
                    </Alert>
                )}

                <nav
                    className="flex flex-wrap gap-2 rounded-lg border bg-muted/20 p-2"
                    role="tablist"
                    aria-label={t('semanticCatalog.resourceSections')}
                >
                    {sections.map((item) => (
                        <Button
                            key={item.key}
                            type="button"
                            size="sm"
                            variant={
                                section === item.key ? 'secondary' : 'ghost'
                            }
                            onClick={() => setSection(item.key)}
                            role="tab"
                            aria-selected={section === item.key}
                            aria-controls={`semantic-section-${item.key}`}
                        >
                            <item.icon aria-hidden="true" />
                            {item.label}
                            {item.count !== undefined && (
                                <Badge variant="outline">{item.count}</Badge>
                            )}
                        </Button>
                    ))}
                </nav>

                {section === 'overview' && (
                    <div
                        id="semantic-section-overview"
                        role="tabpanel"
                        className="space-y-6"
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Database aria-hidden="true" />
                                    {t('semanticCatalog.technicalIdentity')}
                                </CardTitle>
                                <CardDescription>
                                    {t(
                                        'semanticCatalog.technicalIdentityDescription',
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-4 text-sm md:grid-cols-2">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {t('semanticCatalog.sourceName')}
                                    </p>
                                    <code>{resource.source_name}</code>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {t('semanticCatalog.apiPath')}
                                    </p>
                                    <code className="break-all">
                                        {resource.api_path}
                                    </code>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <UserRound aria-hidden="true" />
                                    {t('semanticCatalog.governanceTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {capabilities.update_resource
                                        ? t(
                                              'semanticCatalog.governanceEditableDescription',
                                          )
                                        : t(
                                              'semanticCatalog.governanceReadonlyDescription',
                                          )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {capabilities.update_resource ? (
                                    <ResourceEditor
                                        resource={resource}
                                        ownerCandidates={ownerCandidates}
                                    />
                                ) : (
                                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'semanticCatalog.businessOwner',
                                                )}
                                            </p>
                                            <p className="font-medium">
                                                {resource.business_owner
                                                    ?.name ??
                                                    t(
                                                        'semanticCatalog.ownerUnassigned',
                                                    )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'semanticCatalog.technicalOwner',
                                                )}
                                            </p>
                                            <p className="font-medium">
                                                {resource.technical_owner
                                                    ?.name ??
                                                    t(
                                                        'semanticCatalog.ownerUnassigned',
                                                    )}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'semanticCatalog.sqlMapping',
                                                )}
                                            </p>
                                            <code>
                                                {[
                                                    resource.sql_table,
                                                    resource.sql_alias,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') || '—'}
                                            </code>
                                        </div>
                                        <div>
                                            <p className="text-xs text-muted-foreground">
                                                {t('semanticCatalog.status')}
                                            </p>
                                            <Badge
                                                variant={
                                                    resource.is_active
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {resource.is_active
                                                    ? t('common.active')
                                                    : t('common.inactive')}
                                            </Badge>
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <BookOpenText aria-hidden="true" />
                                    {t('semanticCatalog.localizedContent')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 xl:grid-cols-3">
                                {LOCALES.map((item) => {
                                    const translation =
                                        resource.translations.find(
                                            (value) => value.locale === item,
                                        );

                                    return (
                                        <section
                                            key={item}
                                            className="space-y-3 rounded-lg border p-4"
                                        >
                                            <h3 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                                {t(
                                                    `semanticCatalog.locale.${item}`,
                                                )}
                                            </h3>
                                            <p className="font-medium">
                                                {translation?.name ||
                                                    resource.source_name}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {translation?.description ??
                                                    t(
                                                        'semanticCatalog.noDefinition',
                                                    )}
                                            </p>
                                            {translation &&
                                                translation.synonyms.length >
                                                    0 && (
                                                    <div className="flex flex-wrap gap-1">
                                                        {translation.synonyms.map(
                                                            (synonym) => (
                                                                <Badge
                                                                    key={
                                                                        synonym
                                                                    }
                                                                    variant="outline"
                                                                >
                                                                    {synonym}
                                                                </Badge>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                        </section>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    </div>
                )}

                {section === 'fields' && (
                    <Card id="semantic-section-fields" role="tabpanel">
                        <CardHeader>
                            <CardTitle>
                                {t('semanticCatalog.fieldsTitle')}
                            </CardTitle>
                            <CardDescription>
                                {t('semanticCatalog.fieldsDescription', {
                                    count: resource.fields.length,
                                })}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-lg border">
                                <DataTable
                                    columns={fieldColumns}
                                    rows={resource.fields}
                                    rowKey={(field) => field.id}
                                    empty={t('semanticCatalog.fieldsEmpty')}
                                    paginated
                                    paginationLabels={{
                                        rowsPerPage: t('table.rowsPerPage'),
                                        of: t('table.of'),
                                        previous: t('table.previous'),
                                        next: t('table.next'),
                                    }}
                                />
                            </div>
                            {resource.fields.some(
                                (field) => field.last_seen_at !== null,
                            ) && (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    {t('semanticCatalog.lastFieldObservation', {
                                        date: formatDate(
                                            resource.fields
                                                .map(
                                                    (field) =>
                                                        field.last_seen_at,
                                                )
                                                .filter(
                                                    (value): value is string =>
                                                        value !== null,
                                                )
                                                .sort()
                                                .at(-1) ?? '',
                                            {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            },
                                        ),
                                    })}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {section === 'relations' && (
                    <Card id="semantic-section-relations" role="tabpanel">
                        <CardHeader>
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <CardTitle>
                                        {t('semanticCatalog.relationsTitle')}
                                    </CardTitle>
                                    <CardDescription>
                                        {t(
                                            'semanticCatalog.relationsDescription',
                                            {
                                                count: resource.relations
                                                    .length,
                                            },
                                        )}
                                    </CardDescription>
                                </div>
                                {capabilities.manage_relations && (
                                    <RelationDialog
                                        resourceKey={resource.resource_key}
                                        targetResources={targetResources}
                                        childResources={childResources}
                                    />
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-lg border">
                                <DataTable
                                    columns={relationColumns}
                                    rows={resource.relations}
                                    rowKey={(relation) => relation.id}
                                    empty={t('semanticCatalog.relationsEmpty')}
                                    paginated
                                    paginationLabels={{
                                        rowsPerPage: t('table.rowsPerPage'),
                                        of: t('table.of'),
                                        previous: t('table.previous'),
                                        next: t('table.next'),
                                    }}
                                />
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
