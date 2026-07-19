<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DataQualityAssertionType;
use App\Enums\DataQualityHealthStatus;
use App\Enums\DataQualityRunStatus;
use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateReferenceDataset;
use App\Models\QueryTemplateRoleAssignment;
use App\Models\QueryTemplateValidationRun;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\FusionManager;
use App\Services\OracleResourceCatalog;
use App\Services\QueryTemplateGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QueryTemplateGovernanceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAnyGovernance', QueryTemplate::class);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{search?: string|null, status?: string|null} $validated */
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(QueryTemplateGovernanceStatus::class)],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? null;
        $templates = QueryTemplate::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where(function ($accessible) use ($actor): void {
                $accessible
                    ->where('technical_owner_user_id', $actor->id)
                    ->orWhereHas('governanceUsers', fn ($users) => $users->whereKey($actor->id));
            }))
            ->with([
                'businessOwner:id,name',
                'technicalOwner:id,name,email',
                'publishedVersion:id,query_template_id,version_number,status,published_at',
                'activeCertification' => fn ($query) => $query->with([
                    'certifiedBy:id,name',
                    'queryTemplateVersion',
                ]),
                'versions' => fn ($query) => $query
                    ->whereNotNull('open_slot')
                    ->select(['id', 'query_template_id', 'version_number', 'status', 'lock_version', 'updated_at']),
            ])
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            }))
            ->when($status !== null && $status !== '', fn ($query) => $query->where('governance_status', $status))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (QueryTemplate $template): array => $this->templatePayload($template));

        return Inertia::render('settings/query-templates/index', [
            'templates' => $templates,
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => array_map(
                fn (QueryTemplateGovernanceStatus $status): string => $status->value,
                QueryTemplateGovernanceStatus::cases(),
            ),
        ]);
    }

    public function show(
        Request $request,
        QueryTemplate $queryTemplate,
        OracleResourceCatalog $catalog,
        FusionManager $fusion,
    ): Response {
        Gate::authorize('viewGovernance', $queryTemplate);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{owner_search?: string|null} $validated */
        $validated = $request->validate([
            'owner_search' => ['nullable', 'string', 'max:100'],
        ]);
        $queryTemplate->load([
            'translations',
            'businessOwner:id,name,email',
            'technicalOwner:id,name,email',
            'governanceUsers:id,name,email',
            'publishedBy:id,name',
            'archivedBy:id,name',
            'publishedVersion',
            'activeCertification' => fn ($query) => $query->with([
                'certifiedBy:id,name',
                'queryTemplateVersion',
            ]),
            'versions' => fn ($query) => $query
                ->whereNotNull('open_slot')
                ->select(['id', 'query_template_id', 'version_number', 'status', 'lock_version', 'updated_at']),
        ]);
        $versions = $queryTemplate->versions()
            ->with(['createdBy:id,name', 'submittedBy:id,name', 'publishedBy:id,name'])
            ->orderByDesc('version_number')
            ->paginate(20, ['*'], 'version_page')
            ->through(fn (QueryTemplateVersion $version): array => $this->versionPayload($version));
        $versionOptions = $queryTemplate->versions()
            ->orderByDesc('version_number')
            ->get([
                'id',
                'query_template_id',
                'restored_from_version_id',
                'version_number',
                'status',
                'lock_version',
                'created_at',
                'published_at',
            ])
            ->map(fn (QueryTemplateVersion $version): array => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
                'restored_from_version_id' => $version->restored_from_version_id,
                'lock_version' => $version->lock_version,
                'created_at' => $version->created_at?->toISOString(),
                'published_at' => $version->published_at?->toISOString(),
            ]);
        $ownerSearch = trim((string) ($validated['owner_search'] ?? ''));
        $ownerCandidates = User::query()
            ->select(['id', 'name', 'email'])
            ->when($ownerSearch !== '', fn ($query) => $query->where(function ($search) use ($ownerSearch): void {
                $search
                    ->where('name', 'like', "%{$ownerSearch}%")
                    ->orWhere('email', 'like', "%{$ownerSearch}%");
            }))
            ->orderBy('name')
            ->limit(25)
            ->get();
        $capabilities = $this->capabilities($queryTemplate);
        $technicalOwnerCandidates = $capabilities['assign_technical_owner']
            ? $ownerCandidates
                ->when($queryTemplate->technicalOwner !== null, fn ($users) => $users->push($queryTemplate->technicalOwner))
                ->when($queryTemplate->businessOwner !== null, fn ($users) => $users->push($queryTemplate->businessOwner))
                ->unique('id')
                ->values()
            : collect();
        $roleCandidates = $capabilities['manage_roles']
            ? $technicalOwnerCandidates
                ->concat($queryTemplate->governanceUsers)
                ->unique('id')
                ->values()
            : collect();
        $candidateIds = $roleCandidates->pluck('id');
        $rolesByUser = $candidateIds->isEmpty()
            ? collect()
            : QueryTemplateRoleAssignment::query()
                ->where('query_template_id', $queryTemplate->id)
                ->whereIn('user_id', $candidateIds)
                ->with('role:id,name')
                ->get()
                ->groupBy('user_id')
                ->map(fn ($assignments): array => $assignments
                    ->pluck('role.name')
                    ->sort()
                    ->values()
                    ->all());
        $qualityRuns = $queryTemplate->validationRuns()
            ->with([
                'queryTemplateVersion:id,query_template_id,version_number',
                'oracleTenant:id,key,label',
                'referenceDataset:id,name,scenario',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $references = $queryTemplate->referenceDatasets()
            ->with([
                'queryTemplateVersion:id,query_template_id,version_number',
                'oracleTenant:id,key,label',
                'capturedBy:id,name',
            ])
            ->latest('captured_at')
            ->limit(20)
            ->get();
        $fusion = $fusion->forUser($actor);

        return Inertia::render('settings/query-templates/show', [
            'template' => [
                ...$this->templatePayload($queryTemplate),
                'description' => $queryTemplate->description,
                'resource_key' => $queryTemplate->resource_key,
                'resource_path' => $queryTemplate->resource_path,
                'category_id' => $queryTemplate->category_id,
                'parameters' => $queryTemplate->parameters,
                'parameter_definitions' => $queryTemplate->parameter_definitions,
                'translations' => $queryTemplate->translationSnapshot(),
            ],
            'versions' => $versions,
            'version_options' => $versionOptions,
            'categories' => Category::query()->orderBy('slug')->get(['id', 'slug']),
            'businessOwnerCandidates' => $ownerCandidates,
            'technicalOwnerCandidates' => $technicalOwnerCandidates->map->only(['id', 'name', 'email']),
            'governanceRoleCandidates' => $roleCandidates->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $rolesByUser->get($user->id, []),
            ]),
            'governanceCapabilities' => $capabilities,
            'oracleResources' => $catalog->suggestions(),
            'ownerSearch' => $ownerSearch,
            'quality' => $this->qualityPayload($queryTemplate, $qualityRuns, $references),
            'tenants' => $fusion->available(),
            'defaultTenant' => $fusion->defaultKey() ?: null,
        ]);
    }

    public function updateTechnicalOwner(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('assignTechnicalOwner', $queryTemplate);
        /** @var array{technical_owner_user_id?: int|null, lock_version: int} $validated */
        $validated = $request->validate([
            'technical_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $technicalOwner = isset($validated['technical_owner_user_id'])
            ? User::query()->findOrFail($validated['technical_owner_user_id'])
            : null;

        try {
            $governance->assignTechnicalOwner(
                $queryTemplate,
                $technicalOwner,
                $actor,
                $validated['lock_version'],
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Responsable technique enregistré.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    public function archive(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateGovernanceService $governance,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('archive', $queryTemplate);
        /** @var array{lock_version: int} $validated */
        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        try {
            $governance->archive($queryTemplate, $actor, $validated['lock_version']);
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Modèle officiel archivé.'),
        ]);

        return to_route('query-template-governance.index');
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

    /** @return array<string, mixed> */
    private function templatePayload(QueryTemplate $template): array
    {
        $openVersion = $template->versions->first();
        $certification = $template->activeCertification;

        return [
            'slug' => $template->slug,
            'name' => $template->name,
            'governance_status' => $template->governance_status->value,
            'is_active' => $template->is_active,
            'sort_order' => $template->sort_order,
            'lock_version' => $template->lock_version,
            'published_at' => $template->published_at?->toISOString(),
            'quality_status' => $template->quality_status->value,
            'quality_score' => $template->quality_score === null ? null : (float) $template->quality_score,
            'quality_checked_at' => $template->quality_checked_at?->toISOString(),
            'quality_failure_streak' => $template->quality_failure_streak,
            'published_version' => $template->publishedVersion === null ? null : [
                'id' => $template->publishedVersion->id,
                'version_number' => $template->publishedVersion->version_number,
            ],
            'open_version' => $openVersion === null ? null : [
                'id' => $openVersion->id,
                'version_number' => $openVersion->version_number,
                'status' => $openVersion->status->value,
                'lock_version' => $openVersion->lock_version,
            ],
            'business_owner' => $template->businessOwner === null ? null : [
                'id' => $template->businessOwner->id,
                'name' => $template->businessOwner->name,
            ],
            'technical_owner' => $template->technicalOwner === null ? null : [
                'id' => $template->technicalOwner->id,
                'name' => $template->technicalOwner->name,
                'email' => $template->technicalOwner->email,
            ],
            'review_due_at' => $template->review_due_at?->toDateString(),
            // A review remains due throughout its calendar date; it only
            // becomes overdue on the following day.
            'is_review_overdue' => $template->review_due_at?->isBefore(today()) ?? false,
            'certification' => $certification === null ? null : [
                'id' => $certification->id,
                'query_template_version_id' => $certification->query_template_version_id,
                'version_number' => $certification->queryTemplateVersion->version_number,
                'public_note' => $certification->public_note,
                'certified_by' => $certification->certifiedBy?->only(['id', 'name']),
                'certified_at' => $certification->certified_at->toISOString(),
                'lock_version' => $certification->lock_version,
                'is_effective' => $certification->isEffectiveFor($template),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function capabilities(QueryTemplate $template): array
    {
        $openVersion = $template->versions->first();
        $restoreVersion = $template->versions()
            ->where('status', QueryTemplateVersionStatus::SUPERSEDED->value)
            ->whereNotNull('published_at')
            ->whereKeyNot($template->published_version_id)
            ->latest('version_number')
            ->first();

        return [
            'view' => Gate::allows('viewGovernance', $template),
            'create_draft' => Gate::allows('createDraft', $template),
            'update_draft' => $openVersion !== null && Gate::allows('updateDraft', [$template, $openVersion]),
            'update_technical_definition' => $openVersion !== null
                && Gate::allows('updateTechnicalDefinition', [$template, $openVersion]),
            'submit' => $openVersion !== null && Gate::allows('submitForReview', [$template, $openVersion]),
            'publish' => $openVersion !== null && Gate::allows('publish', [$template, $openVersion]),
            'restore' => $restoreVersion !== null && Gate::allows('restoreVersion', [$template, $restoreVersion]),
            'certify' => Gate::allows('certify', $template),
            'revoke_certification' => $template->activeCertification !== null
                && Gate::allows('revokeCertification', [$template, $template->activeCertification]),
            'archive' => Gate::allows('archive', $template),
            'manage_roles' => Gate::allows('manageRoles', $template),
            'assign_technical_owner' => Gate::allows('assignTechnicalOwner', $template),
            'run_quality_validation' => $openVersion !== null
                ? Gate::allows('runQualityValidation', [$template, $openVersion])
                : ($template->publishedVersion !== null
                    && Gate::allows('runQualityValidation', [$template, $template->publishedVersion])),
            'capture_quality_reference' => $openVersion !== null
                && Gate::allows('captureQualityReference', [$template, $openVersion]),
        ];
    }

    /** @return array<string, mixed> */
    private function versionPayload(QueryTemplateVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'restored_from_version_id' => $version->restored_from_version_id,
            'definition' => $version->definition,
            'translations' => $version->translations,
            'quality_rules' => $version->quality_rules ?? [],
            'change_summary' => $version->change_summary,
            'content_hash' => $version->content_hash,
            'lock_version' => $version->lock_version,
            'created_by' => $version->createdBy?->only(['id', 'name']),
            'submitted_by' => $version->submittedBy?->only(['id', 'name']),
            'published_by' => $version->publishedBy?->only(['id', 'name']),
            'submitted_at' => $version->submitted_at?->toISOString(),
            'published_at' => $version->published_at?->toISOString(),
            'created_at' => $version->created_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, QueryTemplateValidationRun>  $runs
     * @param  Collection<int, QueryTemplateReferenceDataset>  $references
     * @return array<string, mixed>
     */
    private function qualityPayload(QueryTemplate $template, $runs, $references): array
    {
        /** @var QueryTemplateValidationRun|null $latest */
        $latest = $runs->first();
        $qualityStatus = $template->quality_status;
        $checkedAt = $template->quality_checked_at;
        $isSlow = $latest !== null && collect($latest->assertion_results ?? [])->contains(
            fn (array $result): bool => ($result['type'] ?? null) === DataQualityAssertionType::MaxDuration->value
                && ($result['passed'] ?? false) !== true,
        );

        return [
            'health' => [
                'status' => $qualityStatus->value,
                'score' => $template->quality_score === null ? null : (float) $template->quality_score,
                'failure_streak' => $template->quality_failure_streak,
                'is_slow' => $isSlow,
                'is_broken' => $latest !== null && $latest->status !== DataQualityRunStatus::Passed,
                'is_stale' => $checkedAt === null || $checkedAt->isBefore(now()->subDays(30)),
                'certification_suspended' => $template->activeCertification !== null
                    && $qualityStatus === DataQualityHealthStatus::Failing,
                'last_run_at' => $checkedAt?->toISOString(),
            ],
            'latest_run' => $latest === null ? null : $this->qualityRunPayload($latest),
            'runs' => $runs->map(fn (QueryTemplateValidationRun $run): array => $this->qualityRunPayload($run))->values(),
            'references' => $references->map(fn (QueryTemplateReferenceDataset $reference): array => [
                'id' => $reference->id,
                'name' => $reference->name,
                'scenario' => $reference->scenario->value,
                'version_number' => $reference->queryTemplateVersion->version_number,
                'tenant_key' => $reference->oracleTenant?->key,
                'rows_count' => $reference->row_count,
                'dataset_hash' => $reference->dataset_hash,
                'captured_at' => $reference->captured_at->toISOString(),
                'captured_by' => $reference->capturedBy?->only(['id', 'name']),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function qualityRunPayload(QueryTemplateValidationRun $run): array
    {
        $results = collect($run->assertion_results ?? []);

        return [
            'id' => $run->id,
            'purpose' => $run->purpose->value,
            'status' => $run->status->value,
            'score' => $run->score === null ? null : (float) $run->score,
            'version_number' => $run->queryTemplateVersion->version_number,
            'tenant_key' => $run->oracleTenant?->key,
            'duration_ms' => $run->duration_ms,
            'rows_count' => $run->row_count,
            'assertions_passed' => $results->where('passed', true)->count(),
            'assertions_failed' => $results->where('passed', false)->count(),
            'assertion_results' => $results->values(),
            'error_code' => $run->error_code,
            'reference_dataset_id' => $run->query_template_reference_dataset_id,
            'started_at' => $run->started_at->toISOString(),
            'finished_at' => $run->finished_at->toISOString(),
        ];
    }
}
