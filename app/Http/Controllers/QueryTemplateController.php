<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryTemplate;
use App\Services\AuditRecorder;
use App\Services\FusionManager;
use App\Services\OracleQueryTool;
use App\Services\OracleResourceCatalog;
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
            ->with('category.translations')
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
        $this->ensureActive($queryTemplate);
        $fusion = $fusion->forUser($request->user());

        return Inertia::render('query-templates/show', [
            'template' => $this->templatePayload($queryTemplate->loadMissing('category.translations'), $catalog, app()->getLocale()),
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
    ): JsonResponse {
        return $this->executeTemplate($request, $queryTemplate, $fusion, $binder, $tool, preview: true);
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
        AuditRecorder $audit,
    ): JsonResponse {
        return $this->executeTemplate($request, $queryTemplate, $fusion, $binder, $tool, preview: false, audit: $audit);
    }

    /**
     * Materialise current runtime values into an independent private query.
     */
    public function duplicate(
        Request $request,
        QueryTemplate $queryTemplate,
        FusionManager $fusion,
        QueryTemplateParameterBinder $binder,
        AuditRecorder $audit,
    ): RedirectResponse {
        $this->ensureActive($queryTemplate);
        $fusion = $fusion->forUser($request->user());
        $validated = $this->validateRuntimeRequest($request, $fusion);
        $tenant = $this->resolveTenant($validated, $fusion);

        try {
            $bound = $binder->bind($queryTemplate, $validated['parameter_values'] ?? []);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'parameter_values' => $e->getMessage(),
            ]);
        }

        /** @var Query $copy */
        $copy = DB::transaction(function () use ($request, $queryTemplate, $fusion, $tenant, $bound): Query {
            return $request->user()->queries()->create([
                'name' => Str::limit(__('Copie de :name', ['name' => $queryTemplate->name]), 255, ''),
                'description' => $queryTemplate->description,
                'resource_path' => $queryTemplate->resource_path,
                'tenant_key' => $tenant,
                'oracle_tenant_id' => $fusion->tenantId($tenant),
                'mode' => 'single',
                'parameters' => $bound['parameters'],
                'visibility' => 'private',
                'category_id' => $queryTemplate->category_id,
                'query_template_id' => $queryTemplate->id,
            ]);
        });

        $audit->record($request->user(), 'query_template.cloned', $queryTemplate, [
            'query_id' => $copy->id,
            'tenant_key' => $tenant,
            'parameter_values' => $bound['values'],
        ]);

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
        bool $preview,
        ?AuditRecorder $audit = null,
    ): JsonResponse {
        $this->ensureActive($queryTemplate);
        $fusion = $fusion->forUser($request->user());
        $validated = $this->validateRuntimeRequest($request, $fusion);
        $tenant = $this->resolveTenant($validated, $fusion);

        try {
            $bound = $binder->bind($queryTemplate, $validated['parameter_values'] ?? []);
            $toolQuery = $binder->toToolQuery($bound['parameters']);
        } catch (InvalidArgumentException $e) {
            return response()->json($this->basePayload($tenant, $e->getMessage()));
        }

        if ($preview) {
            $toolQuery['limit'] = min(25, max(1, (int) ($toolQuery['limit'] ?? 25)));
        } else {
            $audit?->record($request->user(), 'query_template.executed', $queryTemplate, [
                'tenant_key' => $tenant,
                'parameter_values' => $bound['values'],
            ]);
        }

        try {
            $result = $tool->run($tenant, $toolQuery);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json($this->basePayload($tenant, $e->getMessage()));
        }

        return response()->json(array_replace($this->basePayload($tenant), [
            'resource' => $result['resource'],
            'parameters' => (object) $result['query'],
            'items' => $result['items'],
            'count' => $result['count'],
            'hasMore' => $result['hasMore'],
            'oracleCalls' => array_map(
                fn (array $call): array => array_replace($call, ['params' => (object) $call['params']]),
                $result['calls'],
            ),
        ]));
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
        $resource = $catalog->find($template->resource_key);

        return [
            'slug' => $template->slug,
            'name' => $template->name,
            'description' => $template->description,
            'resource_key' => $template->resource_key,
            'resource_path' => $template->resource_path,
            'resource' => $resource === null ? null : $catalog->toSuggestion($resource),
            'category' => $template->category === null ? null : [
                'slug' => $template->category->slug,
                'name' => $template->category->nameFor($locale),
                'color' => $template->category->color,
            ],
            'parameter_definitions' => $template->parameter_definitions,
        ];
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
        abort_unless($queryTemplate->is_active, 404);
    }
}
