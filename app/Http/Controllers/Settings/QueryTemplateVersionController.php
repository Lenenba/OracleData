<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\OracleResourceCatalog;
use App\Services\QueryTemplateGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QueryTemplateVersionController extends Controller
{
    public function store(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('createDraft', $queryTemplate);
        /** @var array{lock_version?: int|null, change_summary?: string|null} $validated */
        $validated = $request->validate([
            'lock_version' => ['nullable', 'integer', 'min:1'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $governance->createDraft(
                $queryTemplate,
                $actor,
                $validated['change_summary'] ?? null,
                $validated['lock_version'] ?? null,
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brouillon créé.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    public function update(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersion $queryTemplateVersion,
        QueryTemplateGovernanceService $governance,
        OracleResourceCatalog $catalog,
    ): JsonResponse|RedirectResponse {
        $this->assertNestedVersion($queryTemplate, $queryTemplateVersion);
        Gate::authorize('updateDraft', [$queryTemplate, $queryTemplateVersion]);
        $technicalFields = ['resource_key', 'resource_path', 'parameters', 'parameter_definitions'];

        if ($request->hasAny($technicalFields)) {
            Gate::authorize('updateTechnicalDefinition', [$queryTemplate, $queryTemplateVersion]);
        }

        /** @var array{name: string, description?: string|null, category_id?: int|null, sort_order: int, translations: array<string, array<string, mixed>>, resource_key?: string, parameters?: array<string, mixed>, parameter_definitions?: list<array<string, mixed>>, change_summary?: string|null, business_owner_user_id?: int|null, review_due_at?: string|null, template_lock_version: int, version_lock_version: int} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.en' => ['required', 'array'],
            'translations.es' => ['required', 'array'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:5000'],
            'translations.*.parameter_labels' => ['sometimes', 'array'],
            'translations.*.parameter_descriptions' => ['sometimes', 'array'],
            'translations.*.parameter_options' => ['sometimes', 'array'],
            'resource_key' => ['sometimes', 'required', 'string', Rule::in($catalog->keys())],
            'resource_path' => ['prohibited'],
            'parameters' => ['sometimes', 'required', 'array:resource_key,fields,expand,joins,child_fields,q,orderBy,limit,offset'],
            'parameters.limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'parameters.offset' => ['nullable', 'integer', 'min:0'],
            'parameters.q' => ['nullable', 'string', 'max:2000'],
            'parameters.orderBy' => ['nullable', 'string', 'max:500'],
            'parameters.child_fields' => ['nullable', 'array'],
            'parameter_definitions' => ['sometimes', 'required', 'array', 'max:100'],
            'parameter_definitions.*' => ['array'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
            'business_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'review_due_at' => ['nullable', 'date_format:Y-m-d'],
            'template_lock_version' => ['required', 'integer', 'min:1'],
            'version_lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $definition = array_replace($queryTemplateVersion->definition, [
            'name' => trim($validated['name']),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'category_id' => $validated['category_id'] ?? null,
            'sort_order' => $validated['sort_order'],
        ]);

        if ($request->hasAny($technicalFields)) {
            $resourceKey = $validated['resource_key'] ?? (string) $queryTemplateVersion->definition['resource_key'];
            $resource = $catalog->find($resourceKey);
            abort_if($resource === null, 422, __('La ressource Oracle sélectionnée est invalide.'));
            $parameters = $validated['parameters'] ?? $queryTemplateVersion->definition['parameters'];
            $parameters['resource_key'] = $resourceKey;
            $definition = array_replace($definition, [
                'resource_key' => $resourceKey,
                'resource_path' => $resource['path'],
                'parameters' => $parameters,
                'parameter_definitions' => $validated['parameter_definitions']
                    ?? $queryTemplateVersion->definition['parameter_definitions'],
            ]);
        }
        $translations = $this->mergeTranslations(
            $queryTemplateVersion->translations,
            $validated['translations'],
        );
        /** @var User $actor */
        $actor = $request->user();
        try {
            $governance->updateDraft(
                $queryTemplate,
                $queryTemplateVersion,
                $actor,
                $definition,
                $translations,
                $validated['change_summary'] ?? null,
                $validated['business_owner_user_id'] ?? null,
                isset($validated['review_due_at']) ? Carbon::parse($validated['review_due_at']) : null,
                $validated['template_lock_version'],
                $validated['version_lock_version'],
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brouillon enregistré.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    public function submit(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersion $queryTemplateVersion,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        $this->assertNestedVersion($queryTemplate, $queryTemplateVersion);
        Gate::authorize('submitForReview', [$queryTemplate, $queryTemplateVersion]);
        $locks = $this->validateLocks($request);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $governance->submitForReview(
                $queryTemplate,
                $queryTemplateVersion,
                $actor,
                $locks['template_lock_version'],
                $locks['version_lock_version'],
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version soumise en revue.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    public function publish(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersion $queryTemplateVersion,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        $this->assertNestedVersion($queryTemplate, $queryTemplateVersion);
        Gate::authorize('publish', [$queryTemplate, $queryTemplateVersion]);
        $locks = $this->validateLocks($request);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $governance->publish(
                $queryTemplate,
                $queryTemplateVersion,
                $actor,
                $locks['template_lock_version'],
                $locks['version_lock_version'],
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version publiée.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    public function restore(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersion $queryTemplateVersion,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('viewGovernance', $queryTemplate);
        $this->assertNestedVersion($queryTemplate, $queryTemplateVersion);
        Gate::authorize('restoreVersion', [$queryTemplate, $queryTemplateVersion]);
        /** @var array{template_lock_version: int, version_lock_version: int, change_summary?: string|null} $validated */
        $validated = $request->validate([
            'template_lock_version' => ['required', 'integer', 'min:1'],
            'version_lock_version' => ['required', 'integer', 'min:1'],
            'change_summary' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $governance->restoreHistoricalVersion(
                $queryTemplate,
                $queryTemplateVersion,
                $actor,
                $validated['template_lock_version'],
                $validated['version_lock_version'],
                $validated['change_summary'] ?? null,
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version restaurée dans un nouveau brouillon.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    private function conflictResponse(
        Request $request,
        ConflictHttpException $exception,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        return back()->withErrors(['governance' => $exception->getMessage()]);
    }

    private function assertNestedVersion(QueryTemplate $template, QueryTemplateVersion $version): void
    {
        abort_unless($version->belongsToTemplate($template), 404);
    }

    /** @return array{template_lock_version: int, version_lock_version: int} */
    private function validateLocks(Request $request): array
    {
        /** @var array{template_lock_version: int, version_lock_version: int} */
        return $request->validate([
            'template_lock_version' => ['required', 'integer', 'min:1'],
            'version_lock_version' => ['required', 'integer', 'min:1'],
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $submitted
     * @return array<string, array<string, mixed>>
     */
    private function mergeTranslations(array $current, array $submitted): array
    {
        $merged = [];

        foreach (['fr', 'en', 'es'] as $locale) {
            $translation = array_replace($current[$locale] ?? [], $submitted[$locale] ?? []);
            $translation['name'] = trim((string) ($translation['name'] ?? ''));
            $translation['description'] = $this->nullableTrim($translation['description'] ?? null);
            $merged[$locale] = $translation;
        }

        return $merged;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
