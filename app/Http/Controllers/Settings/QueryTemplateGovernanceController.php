<?php

namespace App\Http\Controllers\Settings;

use App\Enums\QueryTemplateGovernanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\QueryTemplateGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        /** @var array{search?: string|null, status?: string|null} $validated */
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(QueryTemplateGovernanceStatus::class)],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? null;
        $templates = QueryTemplate::query()
            ->with([
                'businessOwner:id,name',
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

    public function show(Request $request, QueryTemplate $queryTemplate): Response
    {
        Gate::authorize('viewGovernance', $queryTemplate);
        /** @var array{owner_search?: string|null} $validated */
        $validated = $request->validate([
            'owner_search' => ['nullable', 'string', 'max:100'],
        ]);
        $queryTemplate->load([
            'translations',
            'businessOwner:id,name,email',
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
            'ownerSearch' => $ownerSearch,
        ]);
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
}
