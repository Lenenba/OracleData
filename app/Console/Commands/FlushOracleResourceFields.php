<?php

namespace App\Console\Commands;

use App\Models\OracleResourceField;
use App\Models\OracleTenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oracle:flush-fields {--tenant= : Clé d\'un tenant à cibler (sinon tous)}')]
#[Description('Vide le cache de schéma des ressources Oracle pour forcer une resynchronisation')]
class FlushOracleResourceFields extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantKey = $this->option('tenant');

        $query = OracleResourceField::query();

        if (is_string($tenantKey) && $tenantKey !== '') {
            $tenantIds = OracleTenant::query()->where('key', $tenantKey)->pluck('id');

            if ($tenantIds->isEmpty()) {
                $this->error("Aucun tenant trouvé pour la clé [{$tenantKey}].");

                return self::FAILURE;
            }

            $query->whereIn('oracle_tenant_id', $tenantIds);
        }

        $deleted = $query->clone()->count();
        $query->delete();

        $this->info("{$deleted} entrée(s) de schéma supprimée(s). Elles seront resondées à la prochaine consultation.");

        return self::SUCCESS;
    }
}
