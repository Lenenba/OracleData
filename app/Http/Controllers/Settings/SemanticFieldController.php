<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSemanticFieldRequest;
use App\Models\SemanticField;
use App\Models\SemanticResource;
use App\Services\SemanticCatalogGovernanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SemanticFieldController extends Controller
{
    public function update(
        UpdateSemanticFieldRequest $request,
        SemanticResource $semanticResource,
        SemanticField $semanticField,
        SemanticCatalogGovernanceService $governance,
    ): RedirectResponse {
        $governance->updateField(
            $semanticResource,
            $semanticField,
            $request->user(),
            $request->validated(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Champ sémantique mis à jour.')]);

        return back();
    }
}
