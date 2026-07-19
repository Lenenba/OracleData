<?php

namespace App\Http\Controllers;

use App\Enums\OracleExecutionPolicy;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateTranslation;
use App\Models\QueryTemplateVersion;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\OracleResourceCatalog;
use App\Services\QueryExecutionRecorder;
use App\Services\QueryTemplateAuditSanitizer;
use App\Services\QueryTemplateParameterBinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class QueryTemplateController extends Controller
{
    /**
     * Display the immutable predefined-query library.
     */
    public function index(OracleResourceCatalog $catalog): Response
    {
        $locale = app()->getLocale();
        $templates = QueryTemplate::query()
            ->active()
            ->with([
                'category.translations',
                'publishedVersion',
                'activeCertification.queryTemplateVersion',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (QueryTemplate $template): array => $this->templatePayload($template, $catalog, $locale))
            ->values();

        return Inertia::render('query-templates/index', [
            'templates' => $templates,
        ]);
    }

    /**
     * Configure and execute one predefined query without changing it.
     */
    public function show(
        Request $request,
        QueryTemplate $queryTemplate,
        FusionManager $fusion,
        OracleResourceCatalog $catalog,
    ): Response {
        [$queryTemplate, $runtimeTemplate] = $this->capturePublishedTemplate($queryTemplate);
        $queryTemplate->loadMissing('category.translations');
        $runtimeTemplate->setRelation('category', $queryTemplate->category);
        $fusion = $fusion->forUser($request->user());

        return Inertia::render('query-templates/show', [
            'template' => $this->templatePayload(
                $runtimeTemplate,
                $catalog,
                app()->getLocale(),
            ),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->defaultKey(),
        ]);
    }

    /**
     * Execute a bounded preview. Runtime values remain ephemeral.
     */
    public function preview(
        Request $request,
        QueryTemplate $queryTemplate,
        FusionManager $fusion,
        QueryTemplateParameterBinder $binder,
        OracleQueryTool $tool,
        QueryExecutionRecorder $executions,
        QueryTemplateAuditSanitizer $auditParameters,
        AuditRecorder $audit,
    ): JsonResponse {
        return $this->executeTemplate(
            $request,
            $queryTemplate,
            $fusion,
            $binder,
            $tool,
            $executions,
            $auditParameters,
            $audit,
            preview: true,
        );
    }

    /**
     * Execute the fully configured template and audit the action.
     */
    public function run(
        Request $request,
        QueryTemplate $queryTemplate,
        FusionManager $fusion,
        QueryTemplateParameterBinder $binder,
        OracleQueryTool $tool,
        QueryExecutionRecorder $executions,
        QueryTemplateAuditSanitizer $auditParameters,
        AuditRecorder $audit,
    ): JsonResponse {
        return $this->executeTemplate(
            $request,
            $queryTemplate,
            $fusion,
            $binder,
            $tool,
            $executions,
            $auditParameters,
            $audit,
            preview: false,
        );
    }

    /**
     * Materialise current runtime values into an independent private query.
     */
    public function duplicate(
        Request $request,
        QueryTemplate $queryTemplate,
        FusionManager $fusion,
        QueryTemplateParameterBinder $binder,
        QueryTemplateAuditSanitizer $auditParameters,
        AuditRecorder $audit,
    ): RedirectResponse {
        [$queryTemplate, $runtimeTemplate, $publishedVersion] = $this->capturePublishedTemplate($queryTemplate);
        $publishedVersionId = $publishedVersion->id;
        $fusion = $fusion->forUser($request->user());
        $validated = $this->validateRuntimeRequest($request, $fusion);
        $tenant = $this->resolveTenant($validated, $fusion);

        try {
            $bound = $binder->bind($runtimeTemplate, $validated['parameter_values'] ?? []);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'parameter_values' => $e->getMessage(),
            ]);
        }

        $auditedParameters = $auditParameters->sanitize($runtimeTemplate, $bound['values']);
        $localizedName = $runtimeTemplate->nameFor(app()->getLocale());
        $localizedDescription = $runtimeTemplate->descriptionFor(app()->getLocale());

        /** @var Query $copy */
        $copy = DB::transaction(function () use (
            $request,
            $queryTemplate,
            $runtimeTemplate,
            $fusion,
            $tenant,
            $bound,
            $audit,
            $auditedParameters,
            $localizedName,
            $localizedDescription,
            $publishedVersionId,
        ): Query {
            $copy = $request->user()->queries()->create([
                'name' => Str::limit(__('Copie de :name', ['name' => $localizedName]), 255, ''),
                'description' => $localizedDescription,
                'resource_path' => $runtimeTemplate->resource_path,
                'tenant_key' => $tenant,
                'oracle_tenant_id' => $fusion->tenantId($tenant),
                'mode' => 'single',
                'execution_policy' => OracleExecutionPolicy::EXACT,
                'parameters' => $bound['parameters'],
                'access_level' => 'private',
                'category_id' => $runtimeTemplate->category_id,
                'query_template_id' => $queryTemplate->id,
            ]);
            $copy->forceFill([
                'query_template_version_id' => $publishedVersionId,
            ])->save();

            $audit->record($request->user(), 'query_template.cloned', $queryTemplate, [
                'query_id' => $copy->id,
                'query_template_version_id' => $publishedVersionId,
                'tenant_key' => $tenant,
                'execution_policy' => OracleExecutionPolicy::EXACT->value,
                'audited_parameters' => $auditedParameters,
            ]);

            return $copy;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Votre copie privée est prête à être modifiée.'),
        ]);

        return to_route('queries.edit', $copy);
    }

    private function executeTemplate(
        Request $request,
        QueryTemplate $queryTemplate,
        FusionManager $fusion,
        QueryTemplateParameterBinder $binder,
        OracleQueryTool $tool,
        QueryExecutionRecorder $executions,
        QueryTemplateAuditSanitizer $auditParameters,
        AuditRecorder $audit,
        bool $preview,
    ): JsonResponse {
        [$queryTemplate, $runtimeTemplate, $publishedVersion] = $this->capturePublishedTemplate($queryTemplate);
        $publishedVersionId = $publishedVersion->id;
        $fusion = $fusion->forUser($request->user());
        $validated = $this->validateRuntimeRequest($request, $fusion);
        $tenant = $this->resolveTenant($validated, $fusion);

        try {
            $bound = $binder->bind($runtimeTemplate, $validated['parameter_values'] ?? []);
            $toolQuery = $binder->toToolQuery($bound['parameters']);
        } catch (InvalidArgumentException $e) {
            return response()->json($this->basePayload($tenant, $e->getMessage()));
        }

        if ($preview) {
            $toolQuery['limit'] = min(25, max(1, (int) ($toolQuery['limit'] ?? 25)));
        }

        // A full run must be auditable before any remote call is made. This
        // also makes an invalid HMAC configuration fail closed without
        // executing a query that could not subsequently be journalled.
        $auditedParameters = $preview
            ? []
            : $auditParameters->sanitize($runtimeTemplate, $bound['values']);

        $startedAt = now();
        $startedAtNs = hrtime(true);

        try {
            $result = $tool->run($tenant, $toolQuery, OracleExecutionPolicy::EXACT);
            $this->assertFilterWasApplied($toolQuery, $result['params']);

            $payload = array_replace($this->basePayload($tenant), [
                'resource' => $result['resource'],
                'parameters' => (object) $result['query'],
                'items' => $result['items'],
                'count' => $result['count'],
                'hasMore' => $result['hasMore'],
                'oracleCalls' => array_map(
                    fn (array $call): array => array_replace($call, ['params' => (object) $call['params']]),
                    $result['calls'],
                ),
            ]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $payload = $this->basePayload($tenant, $e->getMessage());
        }

        $durationMs = (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
        $purpose = $preview ? QueryExecution::PURPOSE_PREVIEW : QueryExecution::PURPOSE_RUN;

        if ($preview) {
            $executions->recordTemplateAction(
                $request->user(),
                $queryTemplate,
                $publishedVersionId,
                $tenant,
                $payload,
                $startedAt,
                $durationMs,
                $purpose,
            );
        } else {
            DB::transaction(function () use (
                $request,
                $queryTemplate,
                $tenant,
                $payload,
                $startedAt,
                $durationMs,
                $purpose,
                $executions,
                $audit,
                $auditedParameters,
                $publishedVersionId,
            ): void {
                $execution = $executions->recordTemplateAction(
                    $request->user(),
                    $queryTemplate,
                    $publishedVersionId,
                    $tenant,
                    $payload,
                    $startedAt,
                    $durationMs,
                    $purpose,
                );

                $audit->record($request->user(), 'query_template.executed', $queryTemplate, [
                    'tenant_key' => $tenant,
                    'query_execution_id' => $execution->id,
                    'query_template_version_id' => $publishedVersionId,
                    'status' => $execution->status,
                    'execution_policy' => OracleExecutionPolicy::EXACT->value,
                    'audited_parameters' => $auditedParameters,
                ]);
            });
        }

        return response()->json($payload);
    }

    /**
     * @return array{tenant?: string|null, parameter_values?: array<string, mixed>}
     */
    private function validateRuntimeRequest(Request $request, FusionManager $fusion): array
    {
        /** @var array{tenant?: string|null, parameter_values?: array<string, mixed>} $validated */
        $validated = $request->validate([
            'tenant' => ['nullable', 'string', Rule::in($fusion->keys())],
            'parameter_values' => ['nullable', 'array'],
        ]);

        return $validated;
    }

    /**
     * @param  array{tenant?: string|null}  $validated
     *
     * @throws ValidationException
     */
    private function resolveTenant(array $validated, FusionManager $fusion): string
    {
        $tenant = (string) ($validated['tenant'] ?? $fusion->defaultKey());

        if ($tenant === '') {
            throw ValidationException::withMessages([
                'tenant' => __('Aucune connexion Oracle active n’est disponible.'),
            ]);
        }

        return $tenant;
    }

    /**
     * @return array<string, mixed>
     */
    private function templatePayload(QueryTemplate $template, OracleResourceCatalog $catalog, string $locale): array
    {
        $publishedVersion = $template->publishedVersion;
        $displayTemplate = $publishedVersion === null
            ? $template
            : $this->runtimeTemplateFromVersion($template, $publishedVersion);
        $resource = $catalog->find($displayTemplate->resource_key);
        $certification = $template->activeCertification;
        $isCertified = $certification?->isEffectiveFor($template) ?? false;

        return [
            'slug' => $displayTemplate->slug,
            'version' => $publishedVersion?->version_number,
            'name' => $displayTemplate->nameFor($locale),
            'description' => $displayTemplate->descriptionFor($locale),
            'resource_key' => $displayTemplate->resource_key,
            'resource_path' => $displayTemplate->resource_path,
            'resource' => $resource === null ? null : $catalog->toSuggestion($resource),
            'category' => $displayTemplate->category === null ? null : [
                'slug' => $displayTemplate->category->slug,
                'name' => $displayTemplate->category->nameFor($locale),
                'color' => $displayTemplate->category->color,
            ],
            'parameter_definitions' => $displayTemplate->parameterDefinitionsFor($locale),
            'is_certified' => $isCertified,
            'certification_note' => $isCertified ? $certification?->public_note : null,
            'certification' => ! $isCertified || $certification === null ? null : [
                'public_note' => $certification->public_note,
                'certified_at' => $certification->certified_at->toISOString(),
                'version_number' => $publishedVersion?->version_number,
            ],
        ];
    }

    /**
     * Capture a coherent, immutable published snapshot under a short lock.
     * The lock is released before validation or any Oracle network call.
     *
     * @return array{QueryTemplate, QueryTemplate, QueryTemplateVersion}
     */
    private function capturePublishedTemplate(QueryTemplate $template): array
    {
        return DB::transaction(function () use ($template): array {
            $lockedTemplate = QueryTemplate::query()
                ->whereKey($template->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureActive($lockedTemplate);

            $publishedVersion = QueryTemplateVersion::query()
                ->whereKey($lockedTemplate->published_version_id)
                ->where('query_template_id', $lockedTemplate->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $publishedVersion->status === QueryTemplateVersionStatus::PUBLISHED,
                404,
            );
            $activeCertification = QueryTemplateCertification::query()
                ->with('queryTemplateVersion')
                ->where('query_template_id', $lockedTemplate->id)
                ->where('query_template_version_id', $publishedVersion->id)
                ->where('active_slot', QueryTemplateCertification::ACTIVE_SLOT)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            $lockedTemplate->setRelation('activeCertification', $activeCertification);

            return [
                $lockedTemplate,
                $this->runtimeTemplateFromVersion($lockedTemplate, $publishedVersion),
                $publishedVersion,
            ];
        });
    }

    /**
     * Build an unsaved template view from one immutable version. This keeps
     * definition, translations and provenance on exactly the same snapshot.
     */
    private function runtimeTemplateFromVersion(
        QueryTemplate $template,
        QueryTemplateVersion $version,
    ): QueryTemplate {
        abort_unless($version->query_template_id === $template->id, 404);
        $definition = $version->definition;
        $runtimeTemplate = clone $template;
        $runtimeTemplate->forceFill([
            'name' => (string) $definition['name'],
            'description' => $definition['description'] ?? null,
            'category_id' => $definition['category_id'] ?? null,
            'resource_key' => (string) $definition['resource_key'],
            'resource_path' => (string) $definition['resource_path'],
            'parameters' => $definition['parameters'] ?? [],
            'parameter_definitions' => $definition['parameter_definitions'] ?? [],
            'sort_order' => (int) ($definition['sort_order'] ?? 0),
            'published_version_id' => $version->id,
        ]);
        $translations = [];

        foreach ($version->translations as $locale => $content) {
            $translation = new QueryTemplateTranslation;
            $translation->forceFill([
                'query_template_id' => $template->id,
                'locale' => $locale,
                'name' => (string) ($content['name'] ?? ''),
                'description' => $content['description'] ?? null,
                'parameter_labels' => $content['parameter_labels'] ?? [],
                'parameter_descriptions' => $content['parameter_descriptions'] ?? [],
                'parameter_options' => $content['parameter_options'] ?? [],
            ]);
            $translations[] = $translation;
        }

        $runtimeTemplate->setRelation(
            'translations',
            (new QueryTemplateTranslation)->newCollection($translations),
        );
        $runtimeTemplate->setRelation('publishedVersion', $version);

        return $runtimeTemplate;
    }

    /**
     * Defense in depth: an exact template result may never claim a filter
     * that was absent or changed on the actual Oracle request.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertFilterWasApplied(array $expected, array $actual): void
    {
        $expectedFilter = trim((string) ($expected['q'] ?? ''));

        if ($expectedFilter === '') {
            return;
        }

        if (! array_key_exists('q', $actual) || $actual['q'] !== $expectedFilter) {
            throw new RuntimeException('Le filtre du modèle n’a pas été appliqué intégralement par Oracle.');
        }
    }

    /** @return array<string, mixed> */
    private function basePayload(string $tenant, ?string $error = null): array
    {
        return [
            'mode' => 'single',
            'tenant' => $tenant,
            'resource' => null,
            'parameters' => null,
            'columns' => null,
            'analysis' => null,
            'items' => [],
            'count' => 0,
            'hasMore' => false,
            'oracleCalls' => [],
            'clarification' => null,
            'error' => $error,
        ];
    }

    private function ensureActive(QueryTemplate $queryTemplate): void
    {
        abort_unless($queryTemplate->isPublished(), 404);
    }
}
