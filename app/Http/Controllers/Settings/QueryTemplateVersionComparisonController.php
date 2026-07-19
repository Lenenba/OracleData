<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use App\Services\QueryTemplateVersionComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QueryTemplateVersionComparisonController extends Controller
{
    public function __invoke(
        Request $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersionComparisonService $comparison,
    ): JsonResponse {
        Gate::authorize('viewGovernance', $queryTemplate);
        /** @var array{from_version_id: int, to_version_id: int} $validated */
        $validated = $request->validate([
            'from_version_id' => ['required', 'integer', 'min:1'],
            'to_version_id' => ['required', 'integer', 'min:1'],
        ]);
        $relations = ['createdBy:id,name', 'submittedBy:id,name', 'publishedBy:id,name'];
        $fromVersion = $queryTemplate->versions()
            ->with($relations)
            ->whereKey($validated['from_version_id'])
            ->firstOrFail();
        $toVersion = $queryTemplate->versions()
            ->with($relations)
            ->whereKey($validated['to_version_id'])
            ->firstOrFail();

        Gate::authorize('compareVersions', [$queryTemplate, $fromVersion, $toVersion]);
        $result = $comparison->compare($queryTemplate, $fromVersion, $toVersion);

        return response()->json([
            'from_version' => $this->versionPayload($fromVersion),
            'to_version' => $this->versionPayload($toVersion),
            ...$result,
        ]);
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
