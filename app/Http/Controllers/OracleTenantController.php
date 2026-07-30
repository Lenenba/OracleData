<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOracleTenantRequest;
use App\Models\OracleTenant;
use App\Rules\SafeOracleBaseUrl;
use App\Services\FusionClient;
use App\Services\FusionManager;
use App\Services\OicClient;
use App\Services\TenantConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Inertia\Inertia;
use Inertia\Response;

class OracleTenantController extends Controller
{
    /**
     * Show Oracle tenant configuration.
     */
    public function index(Request $request, FusionManager $fusion): Response
    {
        $fusion = $fusion->forUser($request->user());

        return Inertia::render('oracle-tenants/index', [
            'tenants' => $fusion->details(),
            'defaultTenant' => $fusion->defaultKey(),
        ]);
    }

    /**
     * Persist a new Oracle tenant with encrypted credentials.
     */
    public function store(
        StoreOracleTenantRequest $request,
        FusionManager $fusion,
        TenantConnectionService $connections,
    ): RedirectResponse {
        $connections->create($request->user(), $request->validated());

        $fusion->forgetResolvedTenants();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant Oracle enregistré.')]);

        return to_route('oracle-tenants.index');
    }

    /**
     * Test connectivity to an Oracle Fusion environment before saving.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:2048', new SafeOracleBaseUrl],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'type'     => ['nullable', 'in:fusion,oic'],
        ]);

        $baseUrl  = rtrim($validated['base_url'], '/');
        $username = $validated['username'];
        $password = $validated['password'];
        $type     = $validated['type'] ?? 'fusion';

        // OIC credentials cannot be probed via the admin REST API without an
        // OAuth token. We accept them as-is and verify on the first real call.
        if ($type === 'oic') {
            return response()->json([
                'ok'      => true,
                'message' => __('Identifiants OIC enregistrés. La connexion sera vérifiée lors du premier accès au monitoring.'),
            ]);
        }

        try {
            $ok = (new FusionClient($baseUrl, $username, $password))->testConnection();
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json([
            'ok'      => $ok,
            'message' => $ok
                ? __('Connexion Oracle réussie.')
                : __("Impossible de joindre cet environnement Oracle. Vérifiez l'URL et les identifiants."),
        ]);
    }

    /**
     * Show the edit form for an existing tenant.
     */
    public function edit(OracleTenant $tenant): Response
    {
        Gate::authorize('view', $tenant);

        $connection = $tenant->authConnections()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->firstOrFail();

        return Inertia::render('oracle-tenants/edit', [
            'tenant' => [
                'id' => $tenant->id,
                'key' => $tenant->key,
                'type' => $tenant->type,
                'label' => $tenant->label,
                'base_url' => $tenant->base_url,
                'username' => $connection->identifier,
                'auth_type' => $connection->auth_type,
                'verified_at' => $connection->verified_at?->toISOString(),
                'is_default' => $tenant->is_default,
                'is_active' => $tenant->is_active,
            ],
        ]);
    }

    /**
     * Update an existing Oracle tenant.
     */
    public function update(
        Request $request,
        OracleTenant $tenant,
        FusionManager $fusion,
        TenantConnectionService $connections,
    ): RedirectResponse {
        Gate::authorize('update', $tenant);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'in:fusion,oic'],
            'base_url' => ['required', 'url', 'max:2048', new SafeOracleBaseUrl],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $connections->update($request->user(), $tenant, $validated);

        $fusion->forgetResolvedTenants();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant Oracle mis à jour.')]);

        return to_route('oracle-tenants.index');
    }

    /**
     * Delete an Oracle tenant from the database.
     */
    public function destroy(
        Request $request,
        OracleTenant $tenant,
        FusionManager $fusion,
        TenantConnectionService $connections,
    ): RedirectResponse {
        Gate::authorize('delete', $tenant);

        $connections->delete($request->user(), $tenant);
        $fusion->forgetResolvedTenants();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant Oracle supprimé.')]);

        return to_route('oracle-tenants.index');
    }
}
