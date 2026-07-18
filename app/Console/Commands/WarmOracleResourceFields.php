<?php

namespace App\Console\Commands;

use App\Models\OracleTenant;
use App\Services\OracleFieldDiscovery;
use App\Services\OracleResourceCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oracle:warm-fields {--tenant= : Clé d\'un tenant à cibler (sinon tous les tenants actifs)} {--user= : Email du propriétaire à cibler}')]
#[Description('Précharge en base les champs de toutes les ressources du catalogue pour chaque tenant, afin que le sélecteur de champs lise la base sans resonder Oracle')]
class WarmOracleResourceFields extends Command
{
    /**
     * Pause entre deux sondes Oracle pour rester courtois avec l'API (µs).
     */
    private const int THROTTLE_MICROSECONDS = 150_000;

    /**
     * Execute the console command.
     */
    public function handle(OracleResourceCatalog $catalog, OracleFieldDiscovery $discovery): int
    {
        $tenantKey = $this->option('tenant');
        $userEmail = $this->option('user');

        $tenants = OracleTenant::query()
            ->where('is_active', true)
            ->when(is_string($tenantKey) && $tenantKey !== '', fn ($query) => $query->where('key', $tenantKey))
            ->when(is_string($userEmail) && $userEmail !== '', fn ($query) => $query
                ->whereHas('user', fn ($owner) => $owner->where('email', $userEmail)))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Aucun tenant actif ne correspond aux critères.');

            return self::FAILURE;
        }

        $resources = $catalog->all();

        foreach ($tenants as $tenant) {
            $this->line("Tenant [{$tenant->key}] (propriétaire #{$tenant->user_id}) :");
            $cached = 0;
            $unavailable = 0;

            foreach ($resources as $resource) {
                foreach ($this->targets($resource) as $child) {
                    $fields = $discovery->discovered($tenant->user_id, $tenant->key, $resource['key'], $child);
                    $label = $resource['key'].($child === null ? '' : ".{$child}");

                    if ($fields === null) {
                        $unavailable++;
                        $this->line("  <fg=yellow>–</> {$label} : indisponible");
                    } else {
                        $cached++;
                        $this->line("  <fg=green>✓</> {$label} : ".count($fields).' champs');
                    }

                    usleep(self::THROTTLE_MICROSECONDS);
                }
            }

            $this->info("  → {$cached} entrée(s) en cache, {$unavailable} indisponible(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Cibles à sonder pour une ressource : la racine puis chaque enfant expand.
     *
     * @param  array{child_resources: list<string>, ...}  $resource
     * @return list<string|null>
     */
    private function targets(array $resource): array
    {
        return [null, ...$resource['child_resources']];
    }
}
