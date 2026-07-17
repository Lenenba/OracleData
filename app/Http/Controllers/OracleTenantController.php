<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOracleTenantRequest;
use App\Models\OracleTenant;
use App\Services\FusionClient;
use App\Services\FusionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OracleTenantController extends Controller
{
    /**
     * Show Oracle tenant configuration.
     */
    public function index(FusionManager $fusion): Response
    {
        return Inertia::render('oracle-tenants/index', [
            'tenants' => $fusion->details(),
            'defaultTenant' => $fusion->defaultKey(),
        ]);
    }

    /**
     * Persist a new Oracle tenant with encrypted credentials.
     */
    public function store(StoreOracleTenantRequest $request, FusionManager $fusion): RedirectResponse
    {
        $data = $request->validated();
        $makeDefault = (bool) ($data['is_default'] ?? false);

        DB::transaction(function () use ($data, $makeDefault): void {
            if ($makeDefault) {
                OracleTenant::query()->update(['is_default' => false]);
            }

            OracleTenant::query()->create([
                ...$data,
                'is_default' => $makeDefault,
                'is_active' => true,
            ]);
        });

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
            'base_url' => ['required', 'url'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $client = new FusionClient(
            baseUrl: rtrim($validated['base_url'], '/'),
            username: $validated['username'],
            password: $validated['password'],
        );

        $ok = $client->testConnection();

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? __('Connexion Oracle réussie.')
                : __("Impossible de joindre cet environnement Oracle. Vérifiez l'URL et les identifiants."),
        ]);
    }

    /**
     * Show the edit form for an existing tenant.
     */
    public function edit(OracleTenant $tenant, FusionManager $fusion): Response
    {
        return Inertia::render('oracle-tenants/edit', [
            'tenant' => [
                'id' => $tenant->id,
                'key' => $tenant->key,
                'label' => $tenant->label,
                'base_url' => $tenant->base_url,
                'username' => $tenant->username,
                'is_default' => $tenant->is_default,
                'is_active' => $tenant->is_active,
            ],
        ]);
    }

    /**
     * Update an existing Oracle tenant.
     */
    public function update(Request $request, OracleTenant $tenant, FusionManager $fusion): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:2048'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $makeDefault = (bool) ($validated['is_default'] ?? false);

        DB::transaction(function () use ($validated, $makeDefault, $tenant): void {
            if ($makeDefault) {
                OracleTenant::query()->where('id', '!=', $tenant->id)->update(['is_default' => false]);
            }

            $updateData = [
                'label' => $validated['label'],
                'base_url' => rtrim($validated['base_url'], '/'),
                'username' => $validated['username'],
                'is_default' => $makeDefault,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ];

            // Only update password if a new value was provided.
            if (filled($validated['password'] ?? null)) {
                $updateData['password'] = $validated['password'];
            }

            $tenant->update($updateData);
        });

        $fusion->forgetResolvedTenants();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant Oracle mis à jour.')]);

        return to_route('oracle-tenants.index');
    }

    /**
     * Delete an Oracle tenant from the database.
     */
    public function destroy(OracleTenant $tenant, FusionManager $fusion): RedirectResponse
    {
        $tenant->delete();
        $fusion->forgetResolvedTenants();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant Oracle supprimé.')]);

        return to_route('oracle-tenants.index');
    }
}
