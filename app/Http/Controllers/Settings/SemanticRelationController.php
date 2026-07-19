<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSemanticRelationRequest;
use App\Http\Requests\UpdateSemanticRelationRequest;
use App\Models\SemanticRelation;
use App\Models\SemanticResource;
use App\Services\SemanticCatalogGovernanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SemanticRelationController extends Controller
{
    public function store(
        StoreSemanticRelationRequest $request,
        SemanticResource $semanticResource,
        SemanticCatalogGovernanceService $governance,
    ): RedirectResponse {
        $governance->createRelation($semanticResource, $request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Relation sémantique créée.')]);

        return back();
    }

    public function update(
        UpdateSemanticRelationRequest $request,
        SemanticResource $semanticResource,
        SemanticRelation $semanticRelation,
        SemanticCatalogGovernanceService $governance,
    ): RedirectResponse {
        $governance->updateRelation(
            $semanticResource,
            $semanticRelation,
            $request->user(),
            $request->validated(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Relation sémantique mise à jour.')]);

        return back();
    }
}
