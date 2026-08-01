<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateQueryParametersRequest;
use App\Models\Query;
use App\Services\QueryParameterBinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Lot 10A — manage parameter definitions for a personal query.
 *
 * Only the query owner may set or clear definitions; the method is distinct
 * from the main query update so that the builder and the parameter editor
 * remain two independent concerns.
 */
class QueryParameterController extends Controller
{
    /**
     * Persist the owner's parameter definitions for a query.
     */
    public function update(
        UpdateQueryParametersRequest $request,
        Query $query,
        QueryParameterBinder $binder,
    ): RedirectResponse {
        Gate::authorize('update', $query);

        $rawDefinitions = $request->validated('parameter_definitions');
        $validatedDefinitions = [];

        if (is_array($rawDefinitions)) {
            foreach ($rawDefinitions as $definition) {
                if (is_array($definition)) {
                    $validatedDefinitions[] = $definition;
                }
            }
        }

        // An empty array clears all definitions; null is treated as "no change"
        // but the form always sends an explicit list.
        $definitions = $binder->validate($validatedDefinitions);

        $query->update(['parameter_definitions' => $definitions === [] ? null : $definitions]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $definitions === []
                ? __('Parametres supprimes.')
                : __('Parametres enregistres.'),
        ]);

        return to_route('queries.show', $query);
    }
}
