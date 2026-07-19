<?php

namespace App\Http\Controllers\Settings;

use App\Enums\QueryTemplateRole;
use App\Http\Controllers\Controller;
use App\Models\QueryTemplate;
use App\Models\User;
use App\Services\QueryTemplateGovernanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QueryTemplateDelegationController extends Controller
{
    public function update(
        Request $request,
        QueryTemplate $queryTemplate,
        User $user,
        QueryTemplateGovernanceService $governance,
    ): RedirectResponse {
        Gate::authorize('manageRoles', $queryTemplate);
        /** @var array{roles: list<string>} $validated */
        $validated = $request->validate([
            'roles' => ['required', 'array', 'max:2'],
            'roles.*' => ['string', 'distinct', Rule::enum(QueryTemplateRole::class)],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $governance->syncRoles($queryTemplate, $user, $actor, $validated['roles']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Délégations éditoriales enregistrées.')]);

        return to_route('query-template-governance.show', $queryTemplate);
    }
}
