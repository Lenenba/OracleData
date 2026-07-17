<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOracleTenantRequest;
use App\Services\FusionManager;
use App\Services\TenantConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasCompletedOnboarding()
            && $this->hasUsableConnection($request)) {
            return to_route('dashboard');
        }

        return Inertia::render('onboarding/connection');
    }

    public function store(
        StoreOracleTenantRequest $request,
        TenantConnectionService $connections,
        FusionManager $fusion,
    ): RedirectResponse {
        if ($request->user()->hasCompletedOnboarding()
            && $this->hasUsableConnection($request)) {
            return to_route('dashboard');
        }

        $data = $request->validated();
        $data['is_default'] = true;

        $connections->create($request->user(), $data, completeOnboarding: true);
        $fusion->forgetResolvedTenants();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Votre première connexion Oracle est prête.'),
        ]);

        return to_route('dashboard');
    }

    private function hasUsableConnection(Request $request): bool
    {
        return $request->user()->authConnections()
            ->where('auth_type', 'basic')
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->exists();
    }
}
