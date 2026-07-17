<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class OracleTenantSeeder extends Seeder
{
    /**
     * Give each demo account its own connection, even when local credentials
     * happen to point at the same Oracle development environment.
     */
    public function run(): void
    {
        $key = (string) config('fusion.default', 'client_x');
        $config = (array) config("fusion.tenants.{$key}", []);

        User::query()->each(function (User $user) use ($key, $config): void {
            $tenant = $user->oracleTenants()->updateOrCreate(
                ['key' => $key],
                [
                    'label' => (string) ($config['label'] ?? 'Oracle Fusion'),
                    'base_url' => (string) ($config['base_url'] ?? 'https://demo.fa.oraclecloud.com'),
                    'is_default' => true,
                    'is_active' => true,
                ],
            );

            $tenant->authConnections()->updateOrCreate(
                ['name' => 'Connexion principale'],
                [
                    'user_id' => $user->id,
                    'auth_type' => 'basic',
                    'identifier' => (string) ($config['username'] ?? 'demo'),
                    'secret' => (string) ($config['password'] ?? 'demo'),
                    'is_default' => true,
                    'is_active' => true,
                    'verified_at' => now(),
                    'last_tested_at' => now(),
                    'last_test_succeeded_at' => now(),
                ],
            );

            $user->forceFill(['onboarding_completed_at' => now()])->save();
        });
    }
}
