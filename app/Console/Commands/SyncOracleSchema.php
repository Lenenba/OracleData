<?php

namespace App\Console\Commands;

use App\Models\OracleTenant;
use App\Services\OracleResourceCatalog;
use App\Services\OracleSchemaSynchronizationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('oracle:sync-schema {--tenant= : Clé d\'un tenant à cibler} {--user= : Email du propriétaire à cibler} {--resource= : Clé d\'une ressource à cibler}')]
#[Description('Synchronise les schémas Oracle autorisés depuis leurs endpoints /describe')]
class SyncOracleSchema extends Command
{
    public function handle(
        OracleResourceCatalog $catalog,
        OracleSchemaSynchronizationService $synchronization,
    ): int {
        $tenantKey = $this->option('tenant');
        $userEmail = $this->option('user');
        $resourceKey = $this->option('resource');
        $resources = is_string($resourceKey) && $resourceKey !== ''
            ? array_filter([$catalog->find($resourceKey)])
            : $catalog->all();

        if ($resources === []) {
            $this->error("Ressource Oracle inconnue : [{$resourceKey}].");

            return self::FAILURE;
        }

        $tenants = OracleTenant::query()
            ->with('user')
            ->where('is_active', true)
            ->when(is_string($tenantKey) && $tenantKey !== '', fn ($query) => $query->where('key', $tenantKey))
            ->when(is_string($userEmail) && $userEmail !== '', fn ($query) => $query
                ->whereHas('user', fn ($owner) => $owner->where('email', $userEmail)))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Aucun tenant actif ne correspond aux critères.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            foreach ($resources as $resource) {
                try {
                    $result = $synchronization->synchronize($tenant->user, $tenant, $resource['key']);
                    $this->line("[{$tenant->key}] {$resource['key']} : {$result['status']}");
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("[{$tenant->key}] {$resource['key']} : {$exception->getMessage()}");
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
