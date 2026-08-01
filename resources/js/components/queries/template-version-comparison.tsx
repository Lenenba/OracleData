import { ArrowLeftRight, CircleHelp, RefreshCw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { TemplateGovernanceStatusBadge } from '@/components/queries/template-governance-status-badge';
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
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import type { TranslationKey } from '@/i18n/i18n-context';
import type {
    QueryTemplateGovernanceVersion,
    QueryTemplateGovernanceVersionOption,
    QueryTemplateVersionStatus,
    QueryTemplateVersionComparison,
    QueryTemplateVersionComparisonChange,
} from '@/types/query-template-governance';

type Props = {
    slug: string;
    versions: QueryTemplateGovernanceVersionOption[];
};

type Translator = (
    key: TranslationKey,
    replacements?: Record<string, string | number>,
) => string;

const STATUS_LABELS: Record<QueryTemplateVersionStatus, TranslationKey> = {
    draft: 'templateGovernance.statusDraft',
    review: 'templateGovernance.statusReview',
    published: 'templateGovernance.statusPublished',
    superseded: 'templateGovernance.statusSuperseded',
};

const comparisonUrl = (slug: string, fromId: number, toId: number) => {
    const params = new URLSearchParams({
        from_version_id: String(fromId),
        to_version_id: String(toId),
    });

    return `/settings/query-templates/${encodeURIComponent(slug)}/versions/compare?${params.toString()}`;
};

function orderedValue(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(orderedValue);
    }

    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value)
                .sort(([left], [right]) => left.localeCompare(right))
                .map(([key, nestedValue]) => [key, orderedValue(nestedValue)]),
        );
    }

    return value;
}

function DisplayValue({ value }: { value: unknown }) {
    const { t } = useI18n();

    if (value === null || value === undefined) {
        return (
            <span className="text-muted-foreground italic">
                {t('templateGovernance.compareAbsentValue')}
            </span>
        );
    }

    if (typeof value === 'string') {
        return <span className="break-words whitespace-pre-wrap">{value}</span>;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return <code className="text-xs break-words">{String(value)}</code>;
    }

    return (
        <pre className="max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs break-words whitespace-pre-wrap">
            {JSON.stringify(orderedValue(value), null, 2)}
        </pre>
    );
}

function VersionContext({
    version,
}: {
    version: QueryTemplateGovernanceVersion;
}) {
    const { t, formatDate } = useI18n();

    return (
        <div className="space-y-2 rounded-md border bg-muted/30 p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong>
                    {t('templateGovernance.versionNumber', {
                        version: version.version_number,
                    })}
                </strong>
                <TemplateGovernanceStatusBadge status={version.status} />
            </div>
            <p className="text-muted-foreground">
                {version.change_summary ??
                    t('templateGovernance.noChangeSummary')}
            </p>
            <p className="text-xs text-muted-foreground">
                {version.published_by?.name ??
                    version.created_by?.name ??
                    t('templateGovernance.unknownAuthor')}
                {version.published_at || version.created_at
                    ? ` · ${formatDate(
                          version.published_at ?? version.created_at ?? '',
                          {
                              dateStyle: 'medium',
                              timeStyle: 'short',
                          },
                      )}`
                    : ''}
            </p>
        </div>
    );
}

function sectionLabel(section: string, t: Translator) {
    if (section === 'metadata') {
        return t('templateGovernance.compareMetadata');
    }

    if (section === 'translations') {
        return t('templateGovernance.compareTranslations');
    }

    if (section === 'technical') {
        return t('templateGovernance.compareTechnical');
    }

    return section;
}

function changeKindLabel(
    change: QueryTemplateVersionComparisonChange,
    t: Translator,
) {
    return change.kind === 'added'
        ? t('templateGovernance.compareAdded')
        : change.kind === 'removed'
          ? t('templateGovernance.compareRemoved')
          : t('templateGovernance.compareChanged');
}

export function TemplateVersionComparison({ slug, versions }: Props) {
    const { t } = useI18n();
    const [fromId, setFromId] = useState<number | null>(
        versions.length > 1 ? versions[1].id : (versions[0]?.id ?? null),
    );
    const [toId, setToId] = useState<number | null>(versions[0]?.id ?? null);
    const [requestState, setRequestState] = useState<{
        key: string | null;
        comparison: QueryTemplateVersionComparison | null;
        error: string | null;
    }>({ key: null, comparison: null, error: null });
    const [reloadKey, setReloadKey] = useState(0);
    const requestKey =
        fromId !== null && toId !== null && fromId !== toId
            ? `${fromId}:${toId}:${reloadKey}`
            : null;
    const loading = requestKey !== null && requestState.key !== requestKey;
    const comparison =
        requestState.key === requestKey ? requestState.comparison : null;
    const error = requestState.key === requestKey ? requestState.error : null;

    useEffect(() => {
        if (fromId === null || toId === null || requestKey === null) {
            return;
        }

        const controller = new AbortController();

        fetch(comparisonUrl(slug, fromId, toId), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;

                    throw new Error(
                        body?.message ?? t('templateGovernance.compareError'),
                    );
                }

                return response.json() as Promise<QueryTemplateVersionComparison>;
            })
            .then((payload) =>
                setRequestState({
                    key: requestKey,
                    comparison: payload,
                    error: null,
                }),
            )
            .catch((caught: unknown) => {
                if (
                    caught instanceof DOMException &&
                    caught.name === 'AbortError'
                ) {
                    return;
                }

                setRequestState({
                    key: requestKey,
                    comparison: null,
                    error:
                        caught instanceof Error
                            ? caught.message
                            : t('templateGovernance.compareError'),
                });
            });

        return () => controller.abort();
    }, [fromId, requestKey, slug, t, toId]);

    const changesBySection = useMemo(() => {
        const groups = new Map<
            string,
            QueryTemplateVersionComparisonChange[]
        >();

        comparison?.changes.forEach((change) => {
            groups.set(change.section, [
                ...(groups.get(change.section) ?? []),
                change,
            ]);
        });

        return [...groups.entries()].sort(([left], [right]) => {
            const order = ['metadata', 'translations', 'technical'];

            return order.indexOf(left) - order.indexOf(right);
        });
    }, [comparison]);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            <ArrowLeftRight aria-hidden="true" />
                            {t('templateGovernance.compareTitle')}
                        </CardTitle>
                        <CardDescription id="version-comparison-description">
                            {t('templateGovernance.compareDescription')}
                        </CardDescription>
                    </div>
                    {comparison && (
                        <Badge variant="outline" aria-live="polite">
                            {t('templateGovernance.compareDifferenceCount', {
                                count: comparison.summary.total,
                            })}
                        </Badge>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-5">
                {versions.length < 2 ? (
                    <Alert>
                        <CircleHelp aria-hidden="true" />
                        <AlertTitle>
                            {t('templateGovernance.compareUnavailableTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            {t(
                                'templateGovernance.compareUnavailableDescription',
                            )}
                        </AlertDescription>
                    </Alert>
                ) : (
                    <>
                        <div
                            className="grid gap-4 md:grid-cols-[1fr_auto_1fr] md:items-end"
                            aria-describedby="version-comparison-description"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="compare-from-version">
                                    {t('templateGovernance.compareFromLabel')}
                                </Label>
                                <select
                                    id="compare-from-version"
                                    value={fromId ?? ''}
                                    onChange={(event) =>
                                        setFromId(Number(event.target.value))
                                    }
                                    disabled={loading}
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    {versions.map((version) => (
                                        <option
                                            key={version.id}
                                            value={version.id}
                                            disabled={version.id === toId}
                                        >
                                            {t(
                                                'templateGovernance.versionOption',
                                                {
                                                    version:
                                                        version.version_number,
                                                    status: t(
                                                        STATUS_LABELS[
                                                            version.status
                                                        ],
                                                    ),
                                                },
                                            )}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <ArrowLeftRight
                                className="mx-auto mb-2 hidden size-5 text-muted-foreground md:block"
                                aria-hidden="true"
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="compare-to-version">
                                    {t('templateGovernance.compareToLabel')}
                                </Label>
                                <select
                                    id="compare-to-version"
                                    value={toId ?? ''}
                                    onChange={(event) =>
                                        setToId(Number(event.target.value))
                                    }
                                    disabled={loading}
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    {versions.map((version) => (
                                        <option
                                            key={version.id}
                                            value={version.id}
                                            disabled={version.id === fromId}
                                        >
                                            {t(
                                                'templateGovernance.versionOption',
                                                {
                                                    version:
                                                        version.version_number,
                                                    status: t(
                                                        STATUS_LABELS[
                                                            version.status
                                                        ],
                                                    ),
                                                },
                                            )}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {loading && (
                            <div
                                className="flex items-center justify-center gap-2 rounded-lg border border-dashed py-10 text-sm text-muted-foreground"
                                aria-live="polite"
                            >
                                <Spinner aria-label={t('common.loading')} />
                                {t('templateGovernance.compareLoading')}
                            </div>
                        )}

                        {error && !loading && (
                            <Alert variant="destructive" aria-live="assertive">
                                <CircleHelp aria-hidden="true" />
                                <AlertTitle>
                                    {t('templateGovernance.compareErrorTitle')}
                                </AlertTitle>
                                <AlertDescription className="space-y-3">
                                    <p>{error}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setReloadKey(
                                                (current) => current + 1,
                                            )
                                        }
                                    >
                                        <RefreshCw aria-hidden="true" />
                                        {t(
                                            'templateGovernance.retryComparison',
                                        )}
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}

                        {comparison && !loading && !error && (
                            <div className="space-y-6" aria-live="polite">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <VersionContext
                                        version={comparison.from_version}
                                    />
                                    <VersionContext
                                        version={comparison.to_version}
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2 text-sm">
                                    <Badge variant="outline">
                                        {t(
                                            'templateGovernance.compareAddedCount',
                                            {
                                                count: comparison.summary.added,
                                            },
                                        )}
                                    </Badge>
                                    <Badge variant="outline">
                                        {t(
                                            'templateGovernance.compareRemovedCount',
                                            {
                                                count: comparison.summary
                                                    .removed,
                                            },
                                        )}
                                    </Badge>
                                    <Badge variant="outline">
                                        {t(
                                            'templateGovernance.compareChangedCount',
                                            {
                                                count: comparison.summary
                                                    .changed,
                                            },
                                        )}
                                    </Badge>
                                </div>

                                {comparison.changes.length === 0 ? (
                                    <Alert>
                                        <CircleHelp aria-hidden="true" />
                                        <AlertTitle>
                                            {t(
                                                'templateGovernance.compareIdenticalTitle',
                                            )}
                                        </AlertTitle>
                                        <AlertDescription>
                                            {t(
                                                'templateGovernance.compareIdenticalDescription',
                                            )}
                                        </AlertDescription>
                                    </Alert>
                                ) : (
                                    changesBySection.map(
                                        ([section, changes]) => (
                                            <section
                                                key={section}
                                                className="space-y-3"
                                                aria-labelledby={`comparison-section-${section}`}
                                            >
                                                <h3
                                                    id={`comparison-section-${section}`}
                                                    className="font-semibold"
                                                >
                                                    {sectionLabel(section, t)}
                                                </h3>
                                                <div className="overflow-x-auto rounded-lg border">
                                                    <table className="w-full min-w-[48rem] text-left text-sm">
                                                        <caption className="sr-only">
                                                            {t(
                                                                'templateGovernance.compareTableCaption',
                                                                {
                                                                    section:
                                                                        sectionLabel(
                                                                            section,
                                                                            t,
                                                                        ),
                                                                },
                                                            )}
                                                        </caption>
                                                        <thead className="bg-muted/70">
                                                            <tr>
                                                                <th
                                                                    scope="col"
                                                                    className="w-1/4 px-4 py-3 font-medium"
                                                                >
                                                                    {t(
                                                                        'templateGovernance.compareField',
                                                                    )}
                                                                </th>
                                                                <th
                                                                    scope="col"
                                                                    className="w-[37.5%] px-4 py-3 font-medium"
                                                                >
                                                                    {t(
                                                                        'templateGovernance.compareBefore',
                                                                        {
                                                                            version:
                                                                                comparison
                                                                                    .from_version
                                                                                    .version_number,
                                                                        },
                                                                    )}
                                                                </th>
                                                                <th
                                                                    scope="col"
                                                                    className="w-[37.5%] px-4 py-3 font-medium"
                                                                >
                                                                    {t(
                                                                        'templateGovernance.compareAfter',
                                                                        {
                                                                            version:
                                                                                comparison
                                                                                    .to_version
                                                                                    .version_number,
                                                                        },
                                                                    )}
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y">
                                                            {changes.map(
                                                                (change) => (
                                                                    <tr
                                                                        key={
                                                                            change.path
                                                                        }
                                                                    >
                                                                        <th
                                                                            scope="row"
                                                                            className="px-4 py-3 align-top font-normal"
                                                                        >
                                                                            <code className="text-xs break-all">
                                                                                {
                                                                                    change.path
                                                                                }
                                                                            </code>
                                                                            <Badge
                                                                                variant="outline"
                                                                                className="mt-2 block w-fit"
                                                                            >
                                                                                {changeKindLabel(
                                                                                    change,
                                                                                    t,
                                                                                )}
                                                                            </Badge>
                                                                        </th>
                                                                        <td className="px-4 py-3 align-top">
                                                                            <DisplayValue
                                                                                value={
                                                                                    change.before
                                                                                }
                                                                            />
                                                                        </td>
                                                                        <td className="bg-muted/25 px-4 py-3 align-top">
                                                                            <DisplayValue
                                                                                value={
                                                                                    change.after
                                                                                }
                                                                            />
                                                                        </td>
                                                                    </tr>
                                                                ),
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </section>
                                        ),
                                    )
                                )}
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
