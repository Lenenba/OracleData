<?php

namespace Database\Seeders;

use App\Models\PersonalApiToken;
use App\Models\User;
use Illuminate\Database\Seeder;

class ApiTokenSeeder extends Seeder
{
    /**
     * Seed demo personal API tokens for the Settings → API Tokens page.
     * Raw secrets are shown below — they are only valid in a local dev environment.
     */
    public function run(): void
    {
        $admin = User::query()->where('email', 'test@example.com')->firstOrFail();
        $analyst = User::query()->where('email', 'analyste@oracledata.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@oracledata.test')->firstOrFail();

        $tokens = [
            [
                'user' => $admin,
                'name' => 'Intégration CI/CD',
                'scopes' => [PersonalApiToken::SCOPE_READ_QUERIES, PersonalApiToken::SCOPE_RUN_QUERIES],
                'daily_limit' => 5000,
                'is_active' => true,
                'last_used_at' => now()->subHours(1),
                'expires_at' => now()->addYear(),
            ],
            [
                'user' => $admin,
                'name' => 'Token lecture seule (expiré)',
                'scopes' => [PersonalApiToken::SCOPE_READ_QUERIES],
                'daily_limit' => 500,
                'is_active' => false,
                'last_used_at' => now()->subMonths(3),
                'expires_at' => now()->subMonth(),
            ],
            [
                'user' => $analyst,
                'name' => 'Script d\'export Python',
                'scopes' => [PersonalApiToken::SCOPE_READ_QUERIES],
                'daily_limit' => 1000,
                'is_active' => true,
                'last_used_at' => now()->subDays(2),
                'expires_at' => now()->addMonths(6),
            ],
            [
                'user' => $finance,
                'name' => 'Tableau de bord Power BI',
                'scopes' => [PersonalApiToken::SCOPE_READ_QUERIES, PersonalApiToken::SCOPE_RUN_QUERIES],
                'daily_limit' => 2000,
                'is_active' => true,
                'last_used_at' => now()->subHours(4),
                'expires_at' => null, // no expiry
            ],
        ];

        foreach ($tokens as $definition) {
            $user = $definition['user'];

            $exists = PersonalApiToken::query()
                ->where('user_id', $user->id)
                ->where('name', $definition['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            PersonalApiToken::create([
                'user_id' => $user->id,
                'name' => $definition['name'],
                'token_hash' => PersonalApiToken::hashSecret(bin2hex(random_bytes(32))),
                'scopes' => $definition['scopes'],
                'daily_limit' => $definition['daily_limit'],
                'is_active' => $definition['is_active'],
                'last_used_at' => $definition['last_used_at'],
                'expires_at' => $definition['expires_at'] ?? null,
            ]);
        }
    }
}
