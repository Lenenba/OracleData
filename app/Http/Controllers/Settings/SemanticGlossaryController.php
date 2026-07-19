<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertSemanticGlossaryTermRequest;
use App\Models\SemanticGlossaryTerm;
use App\Services\SemanticCatalogGovernanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SemanticGlossaryController extends Controller
{
    public function store(
        UpsertSemanticGlossaryTermRequest $request,
        SemanticCatalogGovernanceService $governance,
    ): RedirectResponse {
        $governance->upsertGlossary(null, $request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terme ajouté au glossaire.')]);

        return back();
    }

    public function update(
        UpsertSemanticGlossaryTermRequest $request,
        SemanticGlossaryTerm $semanticGlossaryTerm,
        SemanticCatalogGovernanceService $governance,
    ): RedirectResponse {
        $governance->upsertGlossary(
            $semanticGlossaryTerm,
            $request->user(),
            $request->validated(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Terme du glossaire mis à jour.')]);

        return back();
    }
}
