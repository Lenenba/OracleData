<?php

namespace App\Http\Controllers;

use App\Models\OracleTenant;
use App\Services\OicClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Monitoring Oracle Integration Cloud (OIC).
 *
 * Chaque action résout la connexion OIC de l'utilisateur (type = 'oic'),
 * construit un OicClient avec les identifiants chiffrés en base, puis
 * délègue à l'API OIC native.
 *
 * Aucune donnée OIC n'est persistée : tout est lu en temps réel.
 */
class OicMonitorController extends Controller
{
    /**
     * List all OIC integrations for the given tenant with their monitoring summary.
     */
    public function index(Request $request, OracleTenant $oracleTenant): Response
    {
        Gate::authorize('view', $oracleTenant);

        $client = $this->buildClient($oracleTenant);

        $integrations = [];
        $monitoringStats = [];
        $error = null;

        try {
            $raw = $client->integrations();
            $integrations = $raw['items'] ?? [];

            $statsRaw = $client->monitoringIntegrations();
            $monitoringStats = collect($statsRaw['items'] ?? [])
                ->keyBy('id')
                ->all();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        // Merge monitoring stats into each integration row.
        $rows = array_map(function (array $integration) use ($monitoringStats): array {
            $stats = $monitoringStats[$integration['id'] ?? ''] ?? [];

            return [
                'id'               => $integration['id'] ?? null,
                'code'             => $integration['code'] ?? null,
                'name'             => $integration['name'] ?? '—',
                'description'      => $integration['description'] ?? null,
                'status'           => $integration['status'] ?? null,
                'style'            => $integration['style'] ?? null,
                'last_updated'     => $integration['lastUpdatedTime'] ?? null,
                'completed_count'  => (int) ($stats['completedInstancesCount'] ?? 0),
                'failed_count'     => (int) ($stats['failedInstancesCount'] ?? 0),
                'aborted_count'    => (int) ($stats['abortedInstancesCount'] ?? 0),
                'processing_count' => (int) ($stats['processingInstancesCount'] ?? 0),
            ];
        }, $integrations);

        return Inertia::render('oic-monitor/index', [
            'tenant'       => $this->tenantShape($oracleTenant),
            'integrations' => $rows,
            'error'        => $error,
        ]);
    }

    /**
     * Detail of one integration with its recent execution instances.
     */
    public function show(Request $request, OracleTenant $oracleTenant, string $integrationId): Response
    {
        Gate::authorize('view', $oracleTenant);

        $client = $this->buildClient($oracleTenant);

        $instances = [];
        $integration = null;
        $error = null;

        try {
            // Load integration metadata.
            $allRaw = $client->integrations(['q' => 'id='.$integrationId]);
            $integration = collect($allRaw['items'] ?? [])
                ->firstWhere('id', $integrationId);

            // Load the 50 most recent instances.
            $instRaw = $client->instances($integrationId, ['limit' => 50]);
            $instances = array_map(fn (array $inst): array => [
                'id'          => $inst['id'] ?? null,
                'status'      => $inst['status'] ?? null,
                'started_at'  => $inst['startTime'] ?? null,
                'finished_at' => $inst['endTime'] ?? null,
                'error'       => $inst['errorMessage'] ?? null,
                'business_id' => $inst['businessIdentifiers'][0]['value'] ?? null,
            ], $instRaw['items'] ?? []);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        return Inertia::render('oic-monitor/show', [
            'tenant'         => $this->tenantShape($oracleTenant),
            'integration_id' => $integrationId,
            'integration'    => $integration,
            'instances'      => $instances,
            'error'          => $error,
        ]);
    }

    /**
     * Global error view across all integrations.
     */
    public function errors(Request $request, OracleTenant $oracleTenant): Response
    {
        Gate::authorize('view', $oracleTenant);

        $client = $this->buildClient($oracleTenant);

        $errors = [];
        $error = null;

        try {
            $raw = $client->errors();
            $errors = array_map(fn (array $e): array => [
                'instance_id'      => $e['id'] ?? null,
                'integration_id'   => $e['integrationId'] ?? null,
                'integration_name' => $e['integrationName'] ?? null,
                'error_message'    => $e['errorMessage'] ?? null,
                'started_at'       => $e['startTime'] ?? null,
            ], $raw['items'] ?? []);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        return Inertia::render('oic-monitor/errors', [
            'tenant' => $this->tenantShape($oracleTenant),
            'errors' => $errors,
            'error'  => $error,
        ]);
    }

    private function buildClient(OracleTenant $tenant): OicClient
    {
        /** @var \App\Models\AuthConnection|null $connection */
        $connection = $tenant->authConnections()
            ->where('auth_type', 'basic')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($connection === null) {
            throw new RuntimeException(__('Aucune connexion active pour ce tenant OIC.'));
        }

        return new OicClient(
            baseUrl: $tenant->base_url,
            username: $connection->identifier,
            password: $connection->secret,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantShape(OracleTenant $tenant): array
    {
        return [
            'id'    => $tenant->id,
            'key'   => $tenant->key,
            'label' => $tenant->label,
        ];
    }
}
