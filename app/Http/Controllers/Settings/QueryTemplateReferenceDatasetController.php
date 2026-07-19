<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ReferenceScenarioType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQueryTemplateReferenceDatasetRequest;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use App\Models\User;
use App\Services\QueryTemplateQualityService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class QueryTemplateReferenceDatasetController extends Controller
{
    public function store(
        StoreQueryTemplateReferenceDatasetRequest $request,
        QueryTemplate $queryTemplate,
        QueryTemplateVersion $queryTemplateVersion,
        QueryTemplateQualityService $quality,
    ): RedirectResponse {
        abort_unless($queryTemplateVersion->belongsToTemplate($queryTemplate), 404);
        /** @var array{name: string, scenario: ReferenceScenarioType|string, tenant: string, parameter_values?: array<string, mixed>, comparison_config?: array<string, mixed>} $validated */
        $validated = $request->validated();
        /** @var User $actor */
        $actor = $request->user();
        $scenario = $validated['scenario'] instanceof ReferenceScenarioType
            ? $validated['scenario']
            : ReferenceScenarioType::from($validated['scenario']);

        $quality->captureReference(
            $queryTemplate,
            $queryTemplateVersion,
            $actor,
            trim($validated['name']),
            $scenario,
            $validated['tenant'],
            $validated['parameter_values'] ?? [],
            $validated['comparison_config'] ?? [],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Jeu de référence capturé sans conserver les données Oracle en clair.'),
        ]);

        return to_route('query-template-governance.show', $queryTemplate);
    }
}
