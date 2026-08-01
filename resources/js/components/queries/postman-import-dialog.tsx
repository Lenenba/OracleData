import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    FileJson,
    LoaderCircle,
    RefreshCw,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useRef, useState } from 'react';
import {
    preview as previewPostmanCollection,
    store as storePostmanCollection,
} from '@/actions/App/Http/Controllers/QueryImportController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
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
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import { ORACLE_CATALOG } from '@/lib/postman-catalog';

const MAX_FILE_BYTES = 5 * 1024 * 1024;

type JsonObject = Record<string, unknown>;

type ImportCandidate = {
    name: string;
    folder: string | null;
    resource_path: string;
    parameters: JsonObject | unknown[];
    semantic_resource_key: string | null;
    warnings: string[];
    already_imported: boolean;
    tenant_specific_path: boolean;
    default_selected: boolean;
};

type ImportPreview = {
    collection: { name: string };
    importable: ImportCandidate[];
    skipped: {
        writes: number;
        describe: number;
        other: number;
        variables: number;
        duplicates: number;
        invalid_path: number;
        too_long: number;
        invalid_parameters: number;
    };
    scanned: number;
    truncated: boolean;
};

type Props = {
    tenants: Record<string, string>;
    defaultTenant: string;
};

function initialTenant(
    tenants: Record<string, string>,
    defaultTenant: string,
): string {
    return tenants[defaultTenant] !== undefined
        ? defaultTenant
        : (Object.keys(tenants)[0] ?? '');
}

function isJsonObject(value: unknown): value is JsonObject {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isImportCandidate(value: unknown): value is ImportCandidate {
    return (
        isJsonObject(value) &&
        typeof value.name === 'string' &&
        (value.folder === null || typeof value.folder === 'string') &&
        typeof value.resource_path === 'string' &&
        (isJsonObject(value.parameters) || Array.isArray(value.parameters)) &&
        (value.semantic_resource_key === null ||
            typeof value.semantic_resource_key === 'string') &&
        Array.isArray(value.warnings) &&
        value.warnings.every((warning) => typeof warning === 'string') &&
        typeof value.already_imported === 'boolean' &&
        typeof value.tenant_specific_path === 'boolean' &&
        typeof value.default_selected === 'boolean'
    );
}

function normalizeCount(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value)
        ? Math.max(0, value)
        : 0;
}

function normalizePreview(value: unknown): ImportPreview | null {
    if (
        !isJsonObject(value) ||
        !isJsonObject(value.collection) ||
        typeof value.collection.name !== 'string' ||
        !Array.isArray(value.importable) ||
        !value.importable.every(isImportCandidate) ||
        !isJsonObject(value.skipped) ||
        typeof value.truncated !== 'boolean'
    ) {
        return null;
    }

    return {
        collection: { name: value.collection.name },
        importable: value.importable,
        skipped: {
            writes: normalizeCount(value.skipped.writes),
            describe: normalizeCount(value.skipped.describe),
            other: normalizeCount(value.skipped.other),
            variables: normalizeCount(value.skipped.variables),
            duplicates: normalizeCount(value.skipped.duplicates),
            invalid_path: normalizeCount(value.skipped.invalid_path),
            too_long: normalizeCount(value.skipped.too_long),
            invalid_parameters: normalizeCount(
                value.skipped.invalid_parameters,
            ),
        },
        scanned: normalizeCount(value.scanned),
        truncated: value.truncated,
    };
}

async function responseError(response: Response): Promise<string | null> {
    const payload = (await response.json().catch(() => null)) as {
        message?: unknown;
        errors?: Record<string, unknown>;
    } | null;

    if (typeof payload?.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    return (
        Object.values(payload?.errors ?? {})
            .flatMap((value) => (Array.isArray(value) ? value : [value]))
            .find((value): value is string => typeof value === 'string') ?? null
    );
}

function recommendedSelection(candidates: ImportCandidate[]): Set<number> {
    return new Set(
        candidates.flatMap((candidate, index) =>
            candidate.default_selected &&
            !candidate.already_imported &&
            !candidate.tenant_specific_path
                ? [index]
                : [],
        ),
    );
}

function warningTranslationKey(warning: string) {
    switch (warning) {
        case 'unsupported_parameters_ignored':
            return 'queries.importWarningUnsupportedParameters' as const;
        case 'invalid_parameters_ignored':
            return 'queries.importWarningInvalidParameters' as const;
        case 'source_host_removed':
            return 'queries.importWarningSourceHost' as const;
        case 'tenant_specific_path':
            return 'queries.importTenantSpecificHint' as const;
        default:
            return null;
    }
}

export function PostmanImportDialog({ tenants, defaultTenant }: Props) {
    const { t, formatNumber } = useI18n();
    const fileInput = useRef<HTMLInputElement>(null);
    const previewRequest = useRef(0);
    const [open, setOpen] = useState(false);
    const [importTab, setImportTab] = useState<'file' | 'catalog'>('file');
    const [catalogId, setCatalogId] = useState<string>('');
    const [catalogLoading, setCatalogLoading] = useState(false);
    const [tenant, setTenant] = useState(() =>
        initialTenant(tenants, defaultTenant),
    );
    const [fileName, setFileName] = useState<string | null>(null);
    const [collection, setCollection] = useState<JsonObject | null>(null);
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [previewing, setPreviewing] = useState(false);
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const tenantEntries = Object.entries(tenants);

    function reset() {
        previewRequest.current += 1;
        setTenant(initialTenant(tenants, defaultTenant));
        setFileName(null);
        setCollection(null);
        setPreview(null);
        setSelected(new Set());
        setPreviewing(false);
        setImporting(false);
        setError(null);
        setCatalogLoading(false);
        setCatalogId('');

        if (fileInput.current !== null) {
            fileInput.current.value = '';
        }
    }

    async function loadFromCatalog(): Promise<void> {
        const entry = ORACLE_CATALOG.find((c) => c.id === catalogId);

        if (entry === undefined) {
            return;
        }

        setCatalogLoading(true);
        setError(null);
        setCollection(null);
        setPreview(null);
        setFileName(null);

        // Load the catalog collection as a static import — no external fetch.
        const col = entry.collection as JsonObject;
        setFileName(entry.label);
        setCollection(col);
        await requestPreview(col, tenant);
        setCatalogLoading(false);
    }

    function changeOpen(nextOpen: boolean) {
        if (!nextOpen && importing) {
            return;
        }

        setOpen(nextOpen);

        if (!nextOpen) {
            reset();
        }
    }

    async function requestPreview(
        nextCollection: JsonObject,
        nextTenant: string,
    ): Promise<void> {
        if (nextTenant === '') {
            setPreview(null);
            setSelected(new Set());
            setError(t('queries.importNoTenant'));

            return;
        }

        const requestId = previewRequest.current + 1;
        previewRequest.current = requestId;
        setPreviewing(true);
        setPreview(null);
        setSelected(new Set());
        setError(null);

        try {
            const response = await fetch(previewPostmanCollection.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    collection: nextCollection,
                    tenant_key: nextTenant,
                }),
            });

            if (!response.ok) {
                throw new Error(
                    (await responseError(response)) ??
                        t('queries.importPreviewFailed'),
                );
            }

            const nextPreview = normalizePreview(await response.json());

            if (nextPreview === null) {
                throw new Error(t('queries.importPreviewFailed'));
            }

            if (previewRequest.current !== requestId) {
                return;
            }

            setPreview(nextPreview);
            setSelected(recommendedSelection(nextPreview.importable));
        } catch (reason) {
            if (previewRequest.current !== requestId) {
                return;
            }

            setError(
                reason instanceof Error && reason.message !== ''
                    ? reason.message
                    : t('queries.importPreviewFailed'),
            );
        } finally {
            if (previewRequest.current === requestId) {
                setPreviewing(false);
            }
        }
    }

    async function chooseFile(file: File | undefined): Promise<void> {
        previewRequest.current += 1;
        setPreview(null);
        setSelected(new Set());
        setCollection(null);
        setError(null);
        setFileName(file?.name ?? null);

        if (file === undefined) {
            return;
        }

        if (file.size > MAX_FILE_BYTES) {
            setError(t('queries.importFileTooLarge'));

            return;
        }

        if (!file.name.toLocaleLowerCase().endsWith('.json')) {
            setError(t('queries.importFileType'));

            return;
        }

        let parsed: unknown;

        try {
            parsed = JSON.parse(await file.text());
        } catch {
            setError(t('queries.importFileInvalidJson'));

            return;
        }

        if (!isJsonObject(parsed) || !Array.isArray(parsed.item)) {
            setError(t('queries.importFileInvalidCollection'));

            return;
        }

        setCollection(parsed);
        await requestPreview(parsed, tenant);
    }

    function changeTenant(nextTenant: string) {
        setTenant(nextTenant);

        if (collection !== null) {
            void requestPreview(collection, nextTenant);
        }
    }

    function toggleCandidate(index: number, checked: boolean) {
        setSelected((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(index);
            } else {
                next.delete(index);
            }

            return next;
        });
    }

    function submitImport() {
        if (
            collection === null ||
            preview === null ||
            selected.size === 0 ||
            tenant === '' ||
            importing
        ) {
            return;
        }

        setImporting(true);
        setError(null);

        router.post(
            storePostmanCollection.url(),
            {
                collection,
                selected: [...selected].sort((left, right) => left - right),
                tenant_key: tenant,
            } as NonNullable<Parameters<typeof router.post>[1]>,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    reset();
                },
                onError: (errors) => {
                    setError(
                        Object.values(errors).find(
                            (message) => message !== '',
                        ) ?? t('queries.importStoreFailed'),
                    );
                },
                onFinish: () => setImporting(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <Upload className="size-4" />
                    {t('queries.importPostman')}
                </Button>
            </DialogTrigger>

            <DialogContent className="flex max-h-[90vh] flex-col overflow-hidden sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{t('queries.importPostmanTitle')}</DialogTitle>
                    <DialogDescription>
                        {t('queries.importPostmanDescription')}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 space-y-4 overflow-y-auto pr-1">
                    <Alert>
                        <ShieldCheck />
                        <AlertTitle>
                            {t('queries.importSecurityTitle')}
                        </AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc space-y-1 pl-4">
                                <li>{t('queries.importSecurityGetOnly')}</li>
                                <li>{t('queries.importSecurityIgnored')}</li>
                                <li>{t('queries.importSecurityNoOracle')}</li>
                            </ul>
                        </AlertDescription>
                    </Alert>

                    {/* ── Oracle environment selector ── */}
                    <div className="grid gap-2">
                        <Label htmlFor="postman-import-tenant">
                            {t('queries.importTenant')}
                        </Label>
                        <Select
                            value={tenant}
                            onValueChange={changeTenant}
                            disabled={
                                tenantEntries.length === 0 ||
                                previewing ||
                                importing
                            }
                        >
                            <SelectTrigger id="postman-import-tenant">
                                <SelectValue
                                    placeholder={t(
                                        'queries.importTenantPlaceholder',
                                    )}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {tenantEntries.map(([key, label]) => (
                                    <SelectItem key={key} value={key}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* ── Source tabs: file vs catalog ── */}
                    <div className="flex gap-1 rounded-lg border bg-muted/30 p-1">
                        {(
                            [
                                {
                                    id: 'file',
                                    icon: Upload,
                                    label: t('queries.importFileTab'),
                                },
                                {
                                    id: 'catalog',
                                    icon: BookOpen,
                                    label: t('queries.importCatalogTab'),
                                },
                            ] as const
                        ).map(({ id, icon: Icon, label }) => (
                            <button
                                key={id}
                                type="button"
                                disabled={previewing || importing}
                                onClick={() => setImportTab(id)}
                                className={[
                                    'flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                    importTab === id
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ].join(' ')}
                            >
                                <Icon className="size-3.5" />
                                {label}
                            </button>
                        ))}
                    </div>

                    {/* ── File tab ── */}
                    {importTab === 'file' && (
                        <div className="grid gap-2">
                            <Label htmlFor="postman-import-file">
                                {t('queries.importFile')}
                            </Label>
                            <Input
                                ref={fileInput}
                                id="postman-import-file"
                                type="file"
                                accept=".json,application/json"
                                disabled={previewing || importing}
                                onChange={(event) =>
                                    void chooseFile(event.target.files?.[0])
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {fileName === null || importTab !== 'file'
                                    ? t('queries.importFileHint')
                                    : t('queries.importFileSelected', {
                                          name: fileName,
                                      })}
                            </p>
                        </div>
                    )}

                    {/* ── Catalog tab ── */}
                    {importTab === 'catalog' && (
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                {t('queries.importCatalogDescription')}
                            </p>
                            {ORACLE_CATALOG.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('queries.importCatalogEmpty')}
                                </p>
                            ) : (
                                <div className="divide-y rounded-lg border">
                                    {ORACLE_CATALOG.map((entry) => (
                                        <label
                                            key={entry.id}
                                            className="flex cursor-pointer items-start gap-3 p-3 transition-colors hover:bg-muted/40"
                                        >
                                            <input
                                                type="radio"
                                                name="catalog-entry"
                                                value={entry.id}
                                                checked={catalogId === entry.id}
                                                disabled={
                                                    previewing || importing
                                                }
                                                onChange={() =>
                                                    setCatalogId(entry.id)
                                                }
                                                className="mt-1"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-medium">
                                                    {entry.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.description}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {entry.itemCount}{' '}
                                                    {t(
                                                        'queries.importPreviewSummary',
                                                        {
                                                            count: entry.itemCount,
                                                        },
                                                    )}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={
                                    catalogId === '' ||
                                    previewing ||
                                    importing ||
                                    catalogLoading
                                }
                                onClick={() => void loadFromCatalog()}
                            >
                                {catalogLoading ? (
                                    <>
                                        <LoaderCircle className="size-4 animate-spin" />
                                        {t('queries.importCatalogLoading')}
                                    </>
                                ) : (
                                    t('queries.importCatalogLoad')
                                )}
                            </Button>
                        </div>
                    )}

                    {tenantEntries.length === 0 && (
                        <Alert variant="destructive">
                            <AlertTriangle />
                            <AlertTitle>
                                {t('queries.importNoTenant')}
                            </AlertTitle>
                        </Alert>
                    )}

                    {error !== null && (
                        <Alert variant="destructive">
                            <AlertTriangle />
                            <AlertTitle>
                                {t('queries.importErrorTitle')}
                            </AlertTitle>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    {previewing && (
                        <div
                            className="flex items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-10 text-sm text-muted-foreground"
                            role="status"
                        >
                            <LoaderCircle className="size-4 animate-spin" />
                            {t('queries.importPreviewLoading')}
                        </div>
                    )}

                    {!previewing && preview !== null && (
                        <section className="space-y-3" aria-live="polite">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-semibold">
                                        {t('queries.importPreviewTitle')}
                                    </h3>
                                    <p className="text-sm font-medium">
                                        {t('queries.importCollectionSummary', {
                                            name: preview.collection.name,
                                            scanned: formatNumber(
                                                preview.scanned,
                                            ),
                                        })}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {t('queries.importPreviewSummary', {
                                            count: formatNumber(
                                                preview.importable.length,
                                            ),
                                        })}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('queries.importSkippedSummary', {
                                            writes: formatNumber(
                                                preview.skipped.writes,
                                            ),
                                            describe: formatNumber(
                                                preview.skipped.describe,
                                            ),
                                            other: formatNumber(
                                                preview.skipped.other,
                                            ),
                                        })}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'queries.importSkippedSafetySummary',
                                            {
                                                variables: formatNumber(
                                                    preview.skipped.variables,
                                                ),
                                                duplicates: formatNumber(
                                                    preview.skipped.duplicates,
                                                ),
                                                invalidPath: formatNumber(
                                                    preview.skipped
                                                        .invalid_path,
                                                ),
                                                tooLong: formatNumber(
                                                    preview.skipped.too_long,
                                                ),
                                                invalidParameters: formatNumber(
                                                    preview.skipped
                                                        .invalid_parameters,
                                                ),
                                            },
                                        )}
                                    </p>
                                </div>

                                {preview.importable.length > 0 && (
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            disabled={importing}
                                            onClick={() =>
                                                setSelected(
                                                    recommendedSelection(
                                                        preview.importable,
                                                    ),
                                                )
                                            }
                                        >
                                            {t(
                                                'queries.importSelectRecommended',
                                            )}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            disabled={importing}
                                            onClick={() =>
                                                setSelected(new Set())
                                            }
                                        >
                                            {t('queries.importClearSelection')}
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {preview.truncated && (
                                <Alert className="border-amber-500/50 bg-amber-500/5">
                                    <AlertTriangle />
                                    <AlertTitle>
                                        {t('queries.importTruncatedTitle')}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {t(
                                            'queries.importTruncatedDescription',
                                        )}
                                    </AlertDescription>
                                </Alert>
                            )}

                            {preview.importable.length === 0 ? (
                                <Alert>
                                    <FileJson />
                                    <AlertTitle>
                                        {t('queries.importNoCandidates')}
                                    </AlertTitle>
                                    <AlertDescription>
                                        {t(
                                            'queries.importNoCandidatesDescription',
                                        )}
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <div className="divide-y rounded-lg border">
                                    {preview.importable.map(
                                        (candidate, index) => {
                                            const candidateId = `postman-candidate-${index}`;
                                            const parameterCount = Object.keys(
                                                candidate.parameters,
                                            ).length;

                                            return (
                                                <label
                                                    key={`${candidate.resource_path}-${index}`}
                                                    htmlFor={candidateId}
                                                    className="flex cursor-pointer items-start gap-3 p-3 transition-colors hover:bg-muted/40 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
                                                >
                                                    <Checkbox
                                                        id={candidateId}
                                                        className="mt-1"
                                                        checked={selected.has(
                                                            index,
                                                        )}
                                                        disabled={
                                                            importing ||
                                                            candidate.already_imported
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggleCandidate(
                                                                index,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                    <span className="min-w-0 flex-1 space-y-1.5">
                                                        {candidate.folder !==
                                                            null && (
                                                            <span className="block text-xs text-muted-foreground">
                                                                {t(
                                                                    'queries.importFolder',
                                                                    {
                                                                        folder: candidate.folder,
                                                                    },
                                                                )}
                                                            </span>
                                                        )}
                                                        <span className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium">
                                                                {candidate.name}
                                                            </span>
                                                            {candidate.already_imported && (
                                                                <Badge variant="secondary">
                                                                    {t(
                                                                        'queries.importAlreadyImported',
                                                                    )}
                                                                </Badge>
                                                            )}
                                                            {candidate.tenant_specific_path && (
                                                                <Badge variant="outline">
                                                                    <AlertTriangle />
                                                                    {t(
                                                                        'queries.importTenantSpecific',
                                                                    )}
                                                                </Badge>
                                                            )}
                                                            {candidate.semantic_resource_key !==
                                                                null && (
                                                                <Badge variant="outline">
                                                                    {t(
                                                                        'queries.importSemanticResource',
                                                                        {
                                                                            key: candidate.semantic_resource_key,
                                                                        },
                                                                    )}
                                                                </Badge>
                                                            )}
                                                        </span>
                                                        <code className="block text-xs break-all text-muted-foreground">
                                                            {
                                                                candidate.resource_path
                                                            }
                                                        </code>
                                                        {parameterCount > 0 && (
                                                            <span className="block text-xs text-muted-foreground">
                                                                {t(
                                                                    'queries.importParameterCount',
                                                                    {
                                                                        count: formatNumber(
                                                                            parameterCount,
                                                                        ),
                                                                    },
                                                                )}
                                                            </span>
                                                        )}
                                                        {candidate.warnings
                                                            .filter(
                                                                (warning) =>
                                                                    warning !==
                                                                    'tenant_specific_path',
                                                            )
                                                            .map((warning) => {
                                                                const key =
                                                                    warningTranslationKey(
                                                                        warning,
                                                                    );

                                                                return (
                                                                    <span
                                                                        key={
                                                                            warning
                                                                        }
                                                                        className="block text-xs text-amber-700 dark:text-amber-400"
                                                                    >
                                                                        {key ===
                                                                        null
                                                                            ? t(
                                                                                  'queries.importWarningGeneric',
                                                                                  {
                                                                                      warning,
                                                                                  },
                                                                              )
                                                                            : t(
                                                                                  key,
                                                                              )}
                                                                    </span>
                                                                );
                                                            })}
                                                        {candidate.already_imported && (
                                                            <span className="block text-xs text-muted-foreground">
                                                                {t(
                                                                    'queries.importAlreadyImportedHint',
                                                                )}
                                                            </span>
                                                        )}
                                                        {candidate.tenant_specific_path && (
                                                            <span className="block text-xs text-amber-700 dark:text-amber-400">
                                                                {t(
                                                                    'queries.importTenantSpecificHint',
                                                                )}
                                                            </span>
                                                        )}
                                                    </span>
                                                </label>
                                            );
                                        },
                                    )}
                                </div>
                            )}

                            {preview.importable.length > 0 && (
                                <p className="text-sm font-medium">
                                    {t('queries.importSelectedCount', {
                                        count: formatNumber(selected.size),
                                    })}
                                </p>
                            )}
                        </section>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={importing}
                        onClick={() => changeOpen(false)}
                    >
                        {t('common.cancel')}
                    </Button>
                    {collection !== null && error !== null && !previewing && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={tenant === '' || importing}
                            onClick={() =>
                                void requestPreview(collection, tenant)
                            }
                        >
                            <RefreshCw className="size-4" />
                            {t('queries.importRetry')}
                        </Button>
                    )}
                    <Button
                        type="button"
                        disabled={
                            collection === null ||
                            preview === null ||
                            selected.size === 0 ||
                            tenant === '' ||
                            previewing ||
                            importing
                        }
                        onClick={submitImport}
                    >
                        {importing && (
                            <LoaderCircle className="size-4 animate-spin" />
                        )}
                        {importing
                            ? t('queries.importSubmitting')
                            : t('queries.importSubmit', {
                                  count: formatNumber(selected.size),
                              })}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
