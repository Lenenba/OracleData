<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOracleTenantRequest;
use App\Models\OracleTenant;
use App\Services\FusionManager;
use Illuminate\Http\RedirectResponse;
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
    public function store(StoreOracleTenantRequest $request): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant Oracle enregistré.')]);

        return to_route('oracle-tenants.index');
    }
}
