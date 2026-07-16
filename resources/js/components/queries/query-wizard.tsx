import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    BarChart3,
    CheckCircle2,
    Eye,
    Globe,
    Lock,
    Save,
    Search,
    Sparkles,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ResourceSuggestion } from '@/components/queries/query-form';
import { QueryResultView } from '@/components/queries/query-result';
import type { QueryResult } from '@/components/queries/query-result';
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
import { readCsrfToken } from '@/lib/csrf';
import queries from '@/routes/queries';

// ─── Types ────────────────────────────────────────────────────────────────────

type Step = 1 | 2 | 3;

type WizardProps = {
    resourceSuggestions: ResourceSuggestion[];
    tenants: Record<string, string>;
    defaultTenant: string;
};

const DOMAIN_COLORS: Record<string, string> = {
    Procurement: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-800',
    Finance:     'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-800',
    HCM:         'bg-violet-50 text-violet-700 border-violet-200 dark:bg-violet-950 dark:text-violet-300 dark:border-violet-800',
    Inventory:   'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950 dark:text-orange-300 dark:border-orange-800',
};

const DEFAULT_LIMIT = 25;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function domainClass(domain: string): string {
    return DOMAIN_COLORS[domain] ?? 'bg-muted text-muted-foreground border-border';
}

function parseLimit(value: string): number {
    const n = Number.parseInt(value, 10);

    return Number.isNaN(n) ? DEFAULT_LIMIT : Math.min(500, Math.max(1, n));
}

function isRecord(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null;
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

// ─── Progress bar ─────────────────────────────────────────────────────────────

function StepBar({ step }: { step: Step }) {
    const steps = [
        { num: 1, label: 'Ressource' },
        { num: 2, label: 'Configuration' },
        { num: 3, label: 'Finaliser' },
    ] as const;

    return (
        <div className="mb-8 flex items-center gap-0">
            {steps.map((s, i) => (
                <div key={s.num} className="flex flex-1 items-center">
                    <div className="flex flex-col items-center gap-1">
                        <div
                            className={[
                                'flex size-8 items-center justify-center rounded-full text-sm font-semibold border-2 transition-colors',
                                step > s.num
                                    ? 'bg-primary border-primary text-primary-foreground'
                                    : step === s.num
                                      ? 'border-primary text-primary bg-background'
                                      : 'border-border text-muted-foreground bg-background',
                            ].join(' ')}
                        >
                            {step > s.num ? <CheckCircle2 className="size-4" /> : s.num}
                        </div>
                        <span
                            className={[
                                'text-xs font-medium whitespace-nowrap',
                                step === s.num
                                    ? 'text-primary'
                                    : 'text-muted-foreground',
                            ].join(' ')}
                        >
                            {s.label}
                        </span>
                    </div>
                    {i < steps.length - 1 && (
                        <div
                            className={[
                                'mb-4 mx-2 h-0.5 flex-1 transition-colors',
                                step > s.num ? 'bg-primary' : 'bg-border',
                            ].join(' ')}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

// ─── Step 1 : Choisir la ressource ────────────────────────────────────────────

function Step1({
    resources,
    selected,
    onSelect,
    onNext,
}: {
    resources: ResourceSuggestion[];
    selected: ResourceSuggestion | null;
    onSelect: (r: ResourceSuggestion) => void;
    onNext: () => void;
}) {
    const [search, setSearch] = useState('');

    const filtered = useMemo(() => {
        const q = search.toLowerCase().trim();

        if (!q) {
return resources;
}

        return resources.filter(
            (r) =>
                r.label.toLowerCase().includes(q) ||
                r.description.toLowerCase().includes(q) ||
                r.domain.toLowerCase().includes(q) ||
                r.keywords.some((k) => k.toLowerCase().includes(q)),
        );
    }, [resources, search]);

    const domains = useMemo(
        () => [...new Set(resources.map((r) => r.domain))],
        [resources],
    );

    return (
        <div className="flex flex-col gap-5">
            <div>
                <h2 className="text-base font-semibold">Choisissez une ressource Oracle</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Sélectionnez le jeu de données que vous souhaitez interroger.
                </p>
            </div>

            {/* Filtre texte */}
            <div className="relative">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    className="pl-9"
                    placeholder="Rechercher : fournisseur, facture, employé…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    autoFocus
                />
            </div>

            {/* Légende domaines */}
            <div className="flex flex-wrap gap-2">
                {domains.map((d) => (
                    <span
                        key={d}
                        className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${domainClass(d)}`}
                    >
                        {d}
                    </span>
                ))}
            </div>

            {/* Grille de ressources */}
            <div className="grid gap-3 sm:grid-cols-2">
                {filtered.map((r) => (
                    <button
                        key={r.key}
                        type="button"
                        onClick={() => onSelect(r)}
                        className={[
                            'group flex flex-col gap-2 rounded-xl border p-4 text-left transition-all hover:border-primary hover:shadow-sm',
                            selected?.key === r.key
                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                : 'border-border bg-card',
                        ].join(' ')}
                    >
                        <div className="flex items-start justify-between gap-2">
                            <span className="font-semibold text-sm">{r.label}</span>
                            <span
                                className={`shrink-0 inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${domainClass(r.domain)}`}
                            >
                                {r.domain}
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground leading-relaxed">
                            {r.description}
                        </p>
                        {r.fields && r.fields.length > 0 && (
                            <div className="mt-1 flex flex-wrap gap-1">
                                {r.fields.slice(0, 5).map((f) => (
                                    <span
                                        key={f}
                                        className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        {f}
                                    </span>
                                ))}
                                {(r.fields.length ?? 0) > 5 && (
                                    <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                                        +{r.fields.length - 5}
                                    </span>
                                )}
                            </div>
                        )}
                    </button>
                ))}

                {filtered.length === 0 && (
                    <div className="col-span-2 rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Aucune ressource ne correspond à « {search} »
                    </div>
                )}
            </div>

            <div className="flex justify-end pt-2">
                <Button onClick={onNext} disabled={!selected}>
                    Suivant
                    <ArrowRight className="ml-1 size-4" />
                </Button>
            </div>
        </div>
    );
}

// ─── Step 2 : Configurer la requête ───────────────────────────────────────────

function Step2({
    resource,
    tenants,
    intent,
    setIntent,
    tenant,
    setTenant,
    limit,
    setLimit,
    selectedFields,
    setSelectedFields,
    onBack,
    onNext,
}: {
    resource: ResourceSuggestion;
    tenants: Record<string, string>;
    intent: string;
    setIntent: (v: string) => void;
    tenant: string;
    setTenant: (v: string) => void;
    limit: string;
    setLimit: (v: string) => void;
    selectedFields: string[];
    setSelectedFields: (v: string[]) => void;
    onBack: () => void;
    onNext: () => void;
}) {
    const tenantKeys = Object.keys(tenants);
    const allFields = resource.fields ?? [];

    function toggleField(field: string) {
        setSelectedFields(
            selectedFields.includes(field)
                ? selectedFields.filter((f) => f !== field)
                : [...selectedFields, field],
        );
    }

    const canContinue = tenant !== '';

    return (
        <div className="flex flex-col gap-6">
            <div>
                <div className="flex items-center gap-2">
                    <h2 className="text-base font-semibold">Configuration de la requête</h2>
                    <Badge variant="secondary">{resource.label}</Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                    Précisez ce que vous souhaitez obtenir sur cette ressource.
                </p>
            </div>

            {/* Demande libre (intent) */}
            <div className="flex flex-col gap-2">
                <Label htmlFor="wizard-intent">
                    <Sparkles className="inline size-3.5 mr-1 text-muted-foreground" />
                    Demande en langage naturel
                    <span className="ml-1 text-muted-foreground font-normal text-xs">(optionnel — si vous voulez filtrer ou trier)</span>
                </Label>
                <Textarea
                    id="wizard-intent"
                    rows={3}
                    value={intent}
                    onChange={(e) => setIntent(e.target.value)}
                    placeholder={`Ex : ${resource.label} avec statut actif, triés par date de création…`}
                />
            </div>

            {/* Champs à inclure */}
            {allFields.length > 0 && (
                <div className="flex flex-col gap-2">
                    <Label>
                        <BarChart3 className="inline size-3.5 mr-1 text-muted-foreground" />
                        Champs à inclure
                        <span className="ml-1 text-muted-foreground font-normal text-xs">
                            ({selectedFields.length === 0 ? 'tous par défaut' : `${selectedFields.length} sélectionné(s)`})
                        </span>
                    </Label>
                    <div className="flex flex-wrap gap-2 rounded-lg border bg-muted/30 p-3">
                        {allFields.map((f) => {
                            const active = selectedFields.includes(f) || selectedFields.length === 0;
                            const checked = selectedFields.includes(f);

                            return (
                                <button
                                    key={f}
                                    type="button"
                                    onClick={() => toggleField(f)}
                                    className={[
                                        'rounded-md border px-2.5 py-1 font-mono text-xs transition-colors',
                                        checked
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : active
                                              ? 'border-border bg-background text-muted-foreground hover:border-primary hover:text-foreground'
                                              : 'border-border bg-background text-muted-foreground/50',
                                    ].join(' ')}
                                >
                                    {f}
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Cliquez sur les champs que vous souhaitez. Aucune sélection = tous les champs renvoyés.
                    </p>
                </div>
            )}

            {/* Limite + Tenant */}
            <div className="flex flex-wrap gap-4">
                <div className="flex max-w-36 flex-col gap-2">
                    <Label htmlFor="wizard-limit">Limite de lignes</Label>
                    <Input
                        id="wizard-limit"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={500}
                        value={limit}
                        onChange={(e) => setLimit(e.target.value)}
                        onBlur={() => setLimit(String(parseLimit(limit)))}
                    />
                </div>

                <div className="flex flex-1 min-w-48 flex-col gap-2">
                    <Label htmlFor="wizard-tenant">Tenant Oracle</Label>
                    <Select
                        value={tenant}
                        onValueChange={setTenant}
                        disabled={tenantKeys.length === 0}
                    >
                        <SelectTrigger id="wizard-tenant">
                            <SelectValue placeholder="Choisir un tenant" />
                        </SelectTrigger>
                        <SelectContent>
                            {tenantKeys.map((k) => (
                                <SelectItem key={k} value={k}>
                                    {tenants[k]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <div className="flex justify-between pt-2">
                <Button variant="ghost" onClick={onBack}>
                    <ArrowLeft className="mr-1 size-4" />
                    Retour
                </Button>
                <Button onClick={onNext} disabled={!canContinue}>
                    Suivant
                    <ArrowRight className="ml-1 size-4" />
                </Button>
            </div>
        </div>
    );
}

// ─── Step 3 : Nommer, prévisualiser & enregistrer ─────────────────────────────

function Step3({
    resource,
    intent,
    tenant,
    tenants,
    limitStr,
    selectedFields,
    onBack,
}: {
    resource: ResourceSuggestion;
    intent: string;
    tenant: string;
    tenants: Record<string, string>;
    limitStr: string;
    selectedFields: string[];
    onBack: () => void;
}) {
    const [name, setName] = useState(resource.label);
    const [visibility, setVisibility] = useState<'private' | 'shared'>('private');
    const [previewStatus, setPreviewStatus] = useState<'idle' | 'loading' | 'done'>('idle');
    const [previewResult, setPreviewResult] = useState<QueryResult | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const limit = parseLimit(limitStr);
    const tenantLabel = tenants[tenant] ?? tenant;

    const isSaveable =
        previewResult !== null &&
        !previewResult.error &&
        !previewError &&
        (previewResult.mode === 'single' ? previewResult.resource !== null : previewResult.mode === 'agent');

    const resolvedName = name.trim() || resource.label;

    // Description lisible sauvegardée avec la requête (pas envoyée au LLM)
    const fullIntent = useMemo(() => {
        const parts: string[] = [resource.label];

        if (intent.trim()) {
            parts.push(intent.trim());
        }

        if (selectedFields.length > 0) {
            parts.push(`champs: ${selectedFields.join(', ')}`);
        }

        return parts.join(' — ');
    }, [resource.label, intent, selectedFields]);

    async function runPreview() {
        setPreviewStatus('loading');
        setPreviewError(null);
        setPreviewResult(null);

        try {
            // Appel direct Oracle sans LLM — la ressource est déjà connue
            const res = await fetch(queries.directPreview.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': readCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource_key: resource.key,
                    tenant,
                    fields: selectedFields.length > 0 ? selectedFields : [],
                    limit,
                }),
            });

            const data = (await res.json().catch(() => null)) as QueryResult | null;

            if (!res.ok || data === null) {
                setPreviewError(readError(data));
                setPreviewStatus('done');

                return;
            }

            setPreviewResult(data);
        } catch {
            setPreviewError("Erreur réseau lors de la préparation de l'aperçu.");
        } finally {
            setPreviewStatus('done');
        }
    }

    function save() {
        if (!previewResult || saving) {
return;
}

        setSaving(true);

        const parameters: Record<string, unknown> =
            previewResult.mode === 'single' && isRecord(previewResult.parameters)
                ? previewResult.parameters
                : {};

        router.post(
            queries.store.url(),
            {
                name: resolvedName,
                description: fullIntent,
                mode: previewResult.mode ?? 'single',
                resource_path: previewResult.resource?.path ?? '',
                tenant_key: tenant,
                visibility,
                parameters: parameters as Record<string, string | number | boolean | null>,
            },
            {
                onError: () => setSaving(false),
            },
        );
    }

    return (
        <div className="flex flex-col gap-6">
            <div>
                <div className="flex items-center gap-2">
                    <h2 className="text-base font-semibold">Finaliser la requête</h2>
                    <Badge variant="secondary">{resource.label}</Badge>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                    Donnez un nom, choisissez la visibilité, puis prévisualisez avant d'enregistrer.
                </p>
            </div>

            {/* Résumé */}
            <div className="rounded-xl border bg-muted/30 p-4 text-sm space-y-1.5">
                <div className="flex gap-2">
                    <span className="w-28 shrink-0 text-muted-foreground">Ressource</span>
                    <span className="font-medium">{resource.label} <span className="text-muted-foreground font-normal">({resource.domain})</span></span>
                </div>
                {intent && (
                    <div className="flex gap-2">
                        <span className="w-28 shrink-0 text-muted-foreground">Filtre</span>
                        <span className="italic text-foreground/80">{intent}</span>
                    </div>
                )}
                {selectedFields.length > 0 && (
                    <div className="flex gap-2">
                        <span className="w-28 shrink-0 text-muted-foreground">Champs</span>
                        <span className="font-mono text-xs">{selectedFields.join(', ')}</span>
                    </div>
                )}
                <div className="flex gap-2">
                    <span className="w-28 shrink-0 text-muted-foreground">Tenant</span>
                    <span>{tenantLabel}</span>
                </div>
                <div className="flex gap-2">
                    <span className="w-28 shrink-0 text-muted-foreground">Limite</span>
                    <span>{limit} lignes</span>
                </div>
            </div>

            {/* Nom + visibilité */}
            <div className="flex flex-wrap gap-4">
                <div className="flex flex-1 min-w-52 flex-col gap-2">
                    <Label htmlFor="wizard-name">Nom de la requête</Label>
                    <Input
                        id="wizard-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={resource.label}
                    />
                </div>

                <div className="flex flex-col gap-2">
                    <Label>Visibilité</Label>
                    <div className="flex gap-3">
                        {(['private', 'shared'] as const).map((v) => (
                            <button
                                key={v}
                                type="button"
                                onClick={() => setVisibility(v)}
                                className={[
                                    'flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                    visibility === v
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-border text-muted-foreground hover:border-primary/50',
                                ].join(' ')}
                            >
                                {v === 'private' ? <Lock className="size-3.5" /> : <Globe className="size-3.5" />}
                                {v === 'private' ? 'Privée' : 'Partagée'}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {/* Prévisualisation */}
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="secondary"
                    onClick={runPreview}
                    disabled={previewStatus === 'loading'}
                >
                    {previewStatus === 'loading' ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Eye data-icon="inline-start" />
                    )}
                    Prévisualiser
                </Button>
                <p className="text-xs text-muted-foreground">
                    {isSaveable
                        ? `✓ Aperçu OK — ${previewResult?.count ?? 0} résultat(s) depuis ${tenantLabel}`
                        : "Lancez un aperçu pour valider avant d'enregistrer."}
                </p>
            </div>

            {previewStatus === 'loading' && (
                <div className="flex flex-col gap-2">
                    <Skeleton className="h-8 w-full" />
                    <Skeleton className="h-8 w-full" />
                    <Skeleton className="h-8 w-3/4" />
                </div>
            )}

            {previewStatus === 'done' && (
                <QueryResultView
                    result={
                        previewError
                            ? {
                                  mode: 'single',
                                  tenant,
                                  resource: null,
                                  parameters: null,
                                  columns: null,
                                  analysis: null,
                                  items: [],
                                  count: 0,
                                  hasMore: false,
                                  oracleCalls: [],
                                  clarification: null,
                                  error: previewError,
                              }
                            : (previewResult as QueryResult)
                    }
                    tenantLabel={tenantLabel}
                />
            )}

            {/* Navigation */}
            <div className="flex items-center justify-between pt-2">
                <Button variant="ghost" onClick={onBack} disabled={saving}>
                    <ArrowLeft className="mr-1 size-4" />
                    Retour
                </Button>
                <Button onClick={save} disabled={!isSaveable || saving}>
                    {saving ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Save data-icon="inline-start" />
                    )}
                    Enregistrer
                </Button>
            </div>
        </div>
    );
}

// ─── QueryWizard (root) ────────────────────────────────────────────────────────

export function QueryWizard({ resourceSuggestions, tenants, defaultTenant }: WizardProps) {
    const [step, setStep] = useState<Step>(1);
    const [selectedResource, setSelectedResource] = useState<ResourceSuggestion | null>(null);
    const [intent, setIntent] = useState('');
    const [tenant, setTenant] = useState(defaultTenant || Object.keys(tenants)[0] || '');
    const [limit, setLimit] = useState(String(DEFAULT_LIMIT));
    const [selectedFields, setSelectedFields] = useState<string[]>([]);
    const topRef = useRef<HTMLDivElement>(null);

    function scrollTop() {
        topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function goTo(s: Step) {
        setStep(s);
        scrollTop();
    }

    return (
        <div ref={topRef} className="max-w-2xl">
            <StepBar step={step} />

            {step === 1 && (
                <Step1
                    resources={resourceSuggestions}
                    selected={selectedResource}
                    onSelect={setSelectedResource}
                    onNext={() => goTo(2)}
                />
            )}

            {step === 2 && selectedResource && (
                <Step2
                    resource={selectedResource}
                    tenants={tenants}
                    intent={intent}
                    setIntent={setIntent}
                    tenant={tenant}
                    setTenant={setTenant}
                    limit={limit}
                    setLimit={setLimit}
                    selectedFields={selectedFields}
                    setSelectedFields={setSelectedFields}
                    onBack={() => goTo(1)}
                    onNext={() => goTo(3)}
                />
            )}

            {step === 3 && selectedResource && (
                <Step3
                    resource={selectedResource}
                    intent={intent}
                    tenant={tenant}
                    tenants={tenants}
                    limitStr={limit}
                    selectedFields={selectedFields}
                    onBack={() => goTo(2)}
                />
            )}
        </div>
    );
}
