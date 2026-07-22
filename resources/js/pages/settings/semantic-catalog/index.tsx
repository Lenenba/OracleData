import { Head, Link, router } from '@inertiajs/react';
import {
    BookOpenText,
    Database,
    GitBranch,
    Languages,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    TableProperties,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { DataTable, StopClick } from '@/components/data-table';
import type { DataTableColumn } from '@/components/data-table';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import semanticGlossary from '@/routes/semantic-glossary';
import type {
    SemanticCatalogIndexProps,
    SemanticCatalogResourceSummary,
    SemanticClassification,
    SemanticDataCategory,
    SemanticGlossaryTerm,
    SemanticGlossaryTranslation,
    SemanticLocale,
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

type GlossaryTranslationDraft = Omit<SemanticGlossaryTranslation, 'locale'>;

type GlossaryDraft = {
    term_key: string;
    domain: string;
    classification: SemanticClassification;
    data_category: SemanticDataCategory;
    is_active: boolean;
    lock_version: number | null;
    translations: Record<SemanticLocale, GlossaryTranslationDraft>;
};

function emptyTranslation(): GlossaryTranslationDraft {
    return {
        term: '',
        definition: '',
        synonyms: [],
        forbidden_terms: [],
        examples: [],
    };
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

function draftFromTerm(term?: SemanticGlossaryTerm): GlossaryDraft {
    const translations = Object.fromEntries(
        LOCALES.map((locale) => {
            const translation = term?.translations.find(
                (item) => item.locale === locale,
            );

            return [
                locale,
                translation
                    ? {
                          term: translation.term,
                          definition: translation.definition ?? '',
                          synonyms: translation.synonyms,
                          forbidden_terms: translation.forbidden_terms,
                          examples: translation.examples,
                      }
                    : emptyTranslation(),
            ];
        }),
    ) as Record<SemanticLocale, GlossaryTranslationDraft>;

    return {
        term_key: term?.term_key ?? '',
        domain: term?.domain ?? '',
        classification: term?.classification ?? 'unclassified',
        data_category: term?.data_category ?? 'general',
        is_active: term?.is_active ?? true,
        lock_version: term?.lock_version ?? null,
        translations,
    };
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

function ClassificationBadge({
    classification,
}: {
    classification: SemanticClassification;
}) {
    const { t } = useI18n();
    const variant =
        classification === 'restricted' || classification === 'confidential'
            ? 'destructive'
            : classification === 'unclassified'
              ? 'outline'
              : 'secondary';

    return (
        <Badge variant={variant}>
            {classificationLabel(classification, t)}
        </Badge>
    );
}

function GlossaryDialog({
    term,
    domains,
}: {
    term?: SemanticGlossaryTerm;
    domains: string[];
}) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [draft, setDraft] = useState<GlossaryDraft>(() =>
        draftFromTerm(term),
    );

    function updateTranslation(
        locale: SemanticLocale,
        key: keyof GlossaryTranslationDraft,
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

        const payload = {
            term_key: draft.term_key,
            domain: draft.domain,
            classification: draft.classification,
            data_category: draft.data_category,
            is_active: draft.is_active,
            lock_version: draft.lock_version ?? undefined,
            translations: Object.fromEntries(
                LOCALES.map((locale) => [
                    locale,
                    {
                        term: draft.translations[locale].term,
                        definition:
                            draft.translations[locale].definition || null,
                        synonyms: draft.translations[locale].synonyms,
                        forbidden_terms:
                            draft.translations[locale].forbidden_terms,
                        examples: draft.translations[locale].examples,
                    },
                ]),
            ),
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);

                if (!term) {
                    setDraft(draftFromTerm());
                }
            },
            onError: (nextErrors: Record<string, string>) =>
                setErrors(nextErrors),
            onFinish: () => setProcessing(false),
        };

        if (term) {
            router.patch(
                semanticGlossary.update.url(term.id),
                payload,
                options,
            );
        } else {
            router.post(semanticGlossary.store.url(), payload, options);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                setOpen(nextOpen);

                if (nextOpen) {
                    setDraft(draftFromTerm(term));
                    setErrors({});
                }
            }}
        >
            <DialogTrigger asChild>
                {term ? (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        aria-label={t('semanticCatalog.glossaryEditFor', {
                            term:
                                translationFor(term.translations, 'fr')?.term ??
                                term.term_key,
                        })}
                    >
                        <Pencil aria-hidden="true" />
                    </Button>
                ) : (
                    <Button type="button">
                        <Plus aria-hidden="true" />
                        {t('semanticCatalog.glossaryCreate')}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {term
                            ? t('semanticCatalog.glossaryEditTitle')
                            : t('semanticCatalog.glossaryCreateTitle')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('semanticCatalog.glossaryFormDescription')}
                    </DialogDescription>
                </DialogHeader>

                <form className="space-y-6" onSubmit={submit}>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`glossary-key-${term?.id ?? 'new'}`}
                            >
                                {t('semanticCatalog.termKey')}
                            </Label>
                            <Input
                                id={`glossary-key-${term?.id ?? 'new'}`}
                                value={draft.term_key}
                                onChange={(event) =>
                                    setDraft((current) => ({
                                        ...current,
                                        term_key: event.target.value,
                                    }))
                                }
                                disabled={Boolean(term)}
                                required
                            />
                            <InputError message={errors.term_key} />
                        </div>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`glossary-domain-${term?.id ?? 'new'}`}
                            >
                                {t('semanticCatalog.domain')}
                            </Label>
                            <Input
                                id={`glossary-domain-${term?.id ?? 'new'}`}
                                value={draft.domain}
                                onChange={(event) =>
                                    setDraft((current) => ({
                                        ...current,
                                        domain: event.target.value,
                                    }))
                                }
                                list="semantic-catalog-domains"
                                required
                            />
                            <datalist id="semantic-catalog-domains">
                                {domains.map((domain) => (
                                    <option key={domain} value={domain} />
                                ))}
                            </datalist>
                            <InputError message={errors.domain} />
                        </div>
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
                            <InputError message={errors.classification} />
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
                                    <Label
                                        htmlFor={`glossary-${term?.id ?? 'new'}-${locale}-term`}
                                    >
                                        {t('semanticCatalog.term')}
                                    </Label>
                                    <Input
                                        id={`glossary-${term?.id ?? 'new'}-${locale}-term`}
                                        value={draft.translations[locale].term}
                                        onChange={(event) =>
                                            updateTranslation(
                                                locale,
                                                'term',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `translations.${locale}.term`
                                            ]
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label
                                        htmlFor={`glossary-${term?.id ?? 'new'}-${locale}-definition`}
                                    >
                                        {t('semanticCatalog.definition')}
                                    </Label>
                                    <Textarea
                                        id={`glossary-${term?.id ?? 'new'}-${locale}-definition`}
                                        value={
                                            draft.translations[locale]
                                                .definition ?? ''
                                        }
                                        onChange={(event) =>
                                            updateTranslation(
                                                locale,
                                                'definition',
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                        required
                                    />
                                </div>
                                {(
                                    [
                                        [
                                            'synonyms',
                                            'semanticCatalog.synonyms',
                                        ],
                                        [
                                            'forbidden_terms',
                                            'semanticCatalog.forbiddenTerms',
                                        ],
                                        [
                                            'examples',
                                            'semanticCatalog.examples',
                                        ],
                                    ] as const
                                ).map(([key, label]) => (
                                    <div key={key} className="grid gap-2">
                                        <Label
                                            htmlFor={`glossary-${term?.id ?? 'new'}-${locale}-${key}`}
                                        >
                                            {t(label)}
                                        </Label>
                                        <Textarea
                                            id={`glossary-${term?.id ?? 'new'}-${locale}-${key}`}
                                            value={textFromList(
                                                draft.translations[locale][key],
                                            )}
                                            onChange={(event) =>
                                                updateTranslation(
                                                    locale,
                                                    key,
                                                    listFromText(
                                                        event.target.value,
                                                    ),
                                                )
                                            }
                                            rows={2}
                                            placeholder={t(
                                                'semanticCatalog.oneValuePerLine',
                                            )}
                                        />
                                    </div>
                                ))}
                            </section>
                        ))}
                    </div>

                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`glossary-active-${term?.id ?? 'new'}`}
                            checked={draft.is_active}
                            onCheckedChange={(checked) =>
                                setDraft((current) => ({
                                    ...current,
                                    is_active: checked === true,
                                }))
                            }
                        />
                        <Label
                            htmlFor={`glossary-active-${term?.id ?? 'new'}`}
                            className="font-normal"
                        >
                            {t('semanticCatalog.activeTerm')}
                        </Label>
                    </div>

                    <InputError
                        message={errors.glossary ?? Object.values(errors)[0]}
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

export default function SemanticCatalogIndex({
    resources,
    glossary,
    summary,
    filters,
    domains,
    capabilities,
}: SemanticCatalogIndexProps) {
    const { t, locale } = useI18n();
    const [search, setSearch] = useState(filters.search);
    const [domain, setDomain] = useState(filters.domain ?? 'all');
    const [classification, setClassification] = useState<
        SemanticClassification | 'all'
    >(filters.classification ?? 'all');
    const [pending, setPending] = useState(false);

    function applyFilters(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setPending(true);
        router.get(
            semanticCatalog.index().url,
            {
                search: search.trim() || undefined,
                domain: domain === 'all' ? undefined : domain,
                classification:
                    classification === 'all' ? undefined : classification,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setPending(false),
            },
        );
    }

    function resetFilters() {
        setSearch('');
        setDomain('all');
        setClassification('all');
        setPending(true);
        router.get(
            semanticCatalog.index().url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setPending(false),
            },
        );
    }

    const resourceColumns = useMemo<
        DataTableColumn<SemanticCatalogResourceSummary>[]
    >(
        () => [
            {
                key: 'resource',
                header: t('semanticCatalog.resource'),
                icon: Database,
                cell: (resource) => (
                    <div className="max-w-sm space-y-1 whitespace-normal">
                        <Link
                            href={semanticCatalog.show(resource.resource_key)}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {translationFor(resource.translations, locale)
                                ?.name || resource.source_name}
                        </Link>
                        <code className="block text-xs text-muted-foreground">
                            {resource.resource_key}
                        </code>
                    </div>
                ),
            },
            {
                key: 'domain',
                header: t('semanticCatalog.domain'),
                cell: (resource) => resource.domain,
            },
            {
                key: 'classification',
                header: t('semanticCatalog.classification'),
                icon: ShieldCheck,
                cell: (resource) => (
                    <div className="flex flex-wrap gap-2">
                        <ClassificationBadge
                            classification={resource.classification}
                        />
                        <Badge variant="outline">
                            {dataCategoryLabel(resource.data_category, t)}
                        </Badge>
                    </div>
                ),
            },
            {
                key: 'coverage',
                header: t('semanticCatalog.coverage'),
                icon: TableProperties,
                cell: (resource) => (
                    <div className="space-y-1 text-sm">
                        <p>
                            {t('semanticCatalog.fieldCoverage', {
                                mapped: resource.mapped_fields_count,
                                total: resource.fields_count,
                            })}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('semanticCatalog.relationCount', {
                                count: resource.relations_count,
                            })}
                        </p>
                    </div>
                ),
            },
            {
                key: 'owners',
                header: t('semanticCatalog.owners'),
                cell: (resource) => (
                    <div className="space-y-1 text-sm">
                        <p>
                            {resource.business_owner?.name ??
                                t('semanticCatalog.ownerUnassigned')}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {resource.technical_owner?.name ??
                                t('semanticCatalog.ownerUnassigned')}
                        </p>
                    </div>
                ),
            },
            {
                key: 'actions',
                header: t('common.actions'),
                align: 'right',
                cell: (resource) => (
                    <StopClick>
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={semanticCatalog.show(
                                    resource.resource_key,
                                )}
                            >
                                {t('semanticCatalog.openResource')}
                            </Link>
                        </Button>
                    </StopClick>
                ),
            },
        ],
        [locale, t],
    );

    const glossaryColumns = useMemo<DataTableColumn<SemanticGlossaryTerm>[]>(
        () => [
            {
                key: 'term',
                header: t('semanticCatalog.term'),
                icon: BookOpenText,
                cell: (term) => {
                    const translation = translationFor(
                        term.translations,
                        locale,
                    );

                    return (
                        <div className="max-w-md space-y-1 whitespace-normal">
                            <p className="font-medium">
                                {translation?.term || term.term_key}
                            </p>
                            <p className="line-clamp-2 text-xs text-muted-foreground">
                                {translation?.definition ??
                                    t('semanticCatalog.noDefinition')}
                            </p>
                        </div>
                    );
                },
            },
            {
                key: 'domain',
                header: t('semanticCatalog.domain'),
                cell: (term) => term.domain,
            },
            {
                key: 'classification',
                header: t('semanticCatalog.classification'),
                cell: (term) => (
                    <ClassificationBadge classification={term.classification} />
                ),
            },
            {
                key: 'translations',
                header: t('semanticCatalog.languages'),
                icon: Languages,
                cell: (term) => (
                    <div className="flex gap-1">
                        {LOCALES.map((item) => (
                            <Badge
                                key={item}
                                variant={
                                    term.translations.some(
                                        (translation) =>
                                            translation.locale === item &&
                                            translation.term !== '',
                                    )
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {t(`semanticCatalog.localeShort.${item}`)}
                            </Badge>
                        ))}
                    </div>
                ),
            },
            {
                key: 'status',
                header: t('semanticCatalog.status'),
                cell: (term) => (
                    <Badge variant={term.is_active ? 'secondary' : 'outline'}>
                        {term.is_active
                            ? t('common.active')
                            : t('common.inactive')}
                    </Badge>
                ),
            },
            ...(capabilities.manage_glossary
                ? [
                      {
                          key: 'actions',
                          header: t('common.actions'),
                          align: 'right' as const,
                          cell: (term: SemanticGlossaryTerm) => (
                              <GlossaryDialog term={term} domains={domains} />
                          ),
                      },
                  ]
                : []),
        ],
        [capabilities.manage_glossary, domains, locale, t],
    );

    const stats = [
        {
            key: 'resources',
            icon: Database,
            value: summary.resources,
            label: t('semanticCatalog.summaryResources'),
        },
        {
            key: 'fields',
            icon: TableProperties,
            value: summary.fields,
            label: t('semanticCatalog.summaryFields'),
        },
        {
            key: 'relations',
            icon: GitBranch,
            value: summary.relations,
            label: t('semanticCatalog.summaryRelations'),
        },
        {
            key: 'glossary',
            icon: BookOpenText,
            value: summary.glossary_terms,
            label: t('semanticCatalog.summaryTerms'),
        },
    ];

    return (
        <>
            <Head title={t('semanticCatalog.pageTitle')} />
            <h1 className="sr-only">{t('semanticCatalog.pageTitle')}</h1>

            <div className="space-y-5">
                <Heading
                    title={t('semanticCatalog.title')}
                    description={t('semanticCatalog.description')}
                    actions={
                        capabilities.manage_glossary ? (
                            <GlossaryDialog domains={domains} />
                        ) : undefined
                    }
                />

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
                        <CardTitle>
                            {t('semanticCatalog.filtersTitle')}
                        </CardTitle>
                        <CardDescription>
                            {t('semanticCatalog.filtersDescription')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-4 xl:grid-cols-[minmax(16rem,1fr)_14rem_14rem_auto] xl:items-end"
                            onSubmit={applyFilters}
                            role="search"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="semantic-catalog-search">
                                    {t('semanticCatalog.search')}
                                </Label>
                                <div className="relative">
                                    <Search
                                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        id="semantic-catalog-search"
                                        type="search"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        placeholder={t(
                                            'semanticCatalog.searchPlaceholder',
                                        )}
                                        className="pl-9"
                                        disabled={pending}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label>{t('semanticCatalog.domain')}</Label>
                                <Select
                                    value={domain}
                                    onValueChange={setDomain}
                                    disabled={pending}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            {t('semanticCatalog.allDomains')}
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
                                <Label>
                                    {t('semanticCatalog.classification')}
                                </Label>
                                <Select
                                    value={classification}
                                    onValueChange={(value) =>
                                        setClassification(
                                            value as
                                                | SemanticClassification
                                                | 'all',
                                        )
                                    }
                                    disabled={pending}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            {t(
                                                'semanticCatalog.allClassifications',
                                            )}
                                        </SelectItem>
                                        {CLASSIFICATIONS.map((item) => (
                                            <SelectItem key={item} value={item}>
                                                {classificationLabel(item, t)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={pending}>
                                    {pending && <Spinner aria-hidden="true" />}
                                    {t('semanticCatalog.applyFilters')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={resetFilters}
                                    disabled={pending}
                                >
                                    {t('semanticCatalog.resetFilters')}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card aria-busy={pending}>
                    <CardHeader>
                        <CardTitle>
                            {t('semanticCatalog.resourcesTitle')}
                        </CardTitle>
                        <CardDescription>
                            {t('semanticCatalog.resourcesDescription', {
                                count: resources.length,
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-hidden rounded-lg border">
                            <DataTable
                                columns={resourceColumns}
                                rows={resources}
                                rowKey={(resource) => resource.id}
                                paginated
                                paginationLabels={{
                                    rowsPerPage: t('table.rowsPerPage'),
                                    of: t('table.of'),
                                    previous: t('table.previous'),
                                    next: t('table.next'),
                                }}
                                onRowClick={(resource) =>
                                    router.visit(
                                        semanticCatalog.show(
                                            resource.resource_key,
                                        ).url,
                                    )
                                }
                                empty={
                                    <div className="space-y-1">
                                        <p className="font-medium text-foreground">
                                            {t(
                                                'semanticCatalog.resourcesEmpty',
                                            )}
                                        </p>
                                        <p>
                                            {t(
                                                'semanticCatalog.resourcesEmptyDescription',
                                            )}
                                        </p>
                                    </div>
                                }
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <CardTitle>
                                    {t('semanticCatalog.glossaryTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {t('semanticCatalog.glossaryDescription', {
                                        count: glossary.length,
                                    })}
                                </CardDescription>
                            </div>
                            {capabilities.manage_glossary && (
                                <GlossaryDialog domains={domains} />
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-hidden rounded-lg border">
                            <DataTable
                                columns={glossaryColumns}
                                rows={glossary}
                                rowKey={(term) => term.id}
                                paginated
                                paginationLabels={{
                                    rowsPerPage: t('table.rowsPerPage'),
                                    of: t('table.of'),
                                    previous: t('table.previous'),
                                    next: t('table.next'),
                                }}
                                empty={
                                    <div className="space-y-1">
                                        <p className="font-medium text-foreground">
                                            {t('semanticCatalog.glossaryEmpty')}
                                        </p>
                                        <p>
                                            {t(
                                                'semanticCatalog.glossaryEmptyDescription',
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
