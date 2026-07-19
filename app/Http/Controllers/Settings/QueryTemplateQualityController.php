<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DataQualityRunPurpose;
use App\Enums\DataQualityRunStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQueryTemplateQualityRunRequest;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateReferenceDataset;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\QueryTemplateQualityService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QueryTemplateQualityController extends Controller
{
    public function store(
        StoreQueryTemplateQualityRunRequest $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersion $queryTemplateVersion,
        QueryTemplateQualityService $quality,
    ): RedirectResponse {
        abort_unless($queryTemplateVersion->belongsToTemplate($queryTemplate), 404);
        /** @var array{tenant: string, parameter_values?: array<string, mixed>, reference_dataset_id?: int|null, purpose?: DataQualityRunPurpose|string|null} $validated */
        $validated = $request->validated();
        /** @var User $actor */
        $actor = $request->user();
        $reference = isset($validated['reference_dataset_id'])
            ? QueryTemplateReferenceDataset::query()
                ->where('query_template_id', $queryTemplate->id)
                ->where('query_template_version_id', $queryTemplateVersion->id)
                ->findOrFail($validated['reference_dataset_id'])
            : null;
        $purpose = $this->purpose($validated['purpose'] ?? null, $queryTemplateVersion);
        $run = $quality->run(
            $queryTemplate,
            $queryTemplateVersion,
            $actor,
            $validated['tenant'],
            $validated['parameter_values'] ?? [],
            $purpose,
            $reference,
        );

        Inertia::flash('toast', [
            'type' => $run->status === DataQualityRunStatus::Passed ? 'success' : 'error',
            'message' => $run->status === DataQualityRunStatus::Passed
                ? __('Validation des données réussie.')
                : __('La validation des données a détecté un échec.'),
        ]);

        return to_route('query-template-governance.show', $queryTemplate);
    }

    private function purpose(
        DataQualityRunPurpose|string|null $requested,
        QueryTemplateVersion $version,
    ): DataQualityRunPurpose {
        if ($requested instanceof DataQualityRunPurpose) {
            return $requested;
        }

        if (is_string($requested) && DataQualityRunPurpose::tryFrom($requested) !== null) {
            return DataQualityRunPurpose::from($requested);
        }

        return match ($version->status) {
            QueryTemplateVersionStatus::REVIEW => DataQualityRunPurpose::PrePublication,
            QueryTemplateVersionStatus::PUBLISHED => DataQualityRunPurpose::Monitoring,
            default => DataQualityRunPurpose::Manual,
        };
    }
}
