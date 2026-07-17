<?php

namespace Database\Factories;

use App\Models\AuthConnection;
use App\Models\OracleTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthConnection>
 */
class AuthConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'oracle_tenant_id' => OracleTenant::factory(),
            'name' => 'Connexion principale',
            'auth_type' => 'basic',
            'identifier' => fake()->userName(),
            'secret' => 'secret',
            'configuration' => null,
            'is_default' => true,
            'is_active' => true,
            'verified_at' => now(),
            'last_tested_at' => now(),
            'last_test_succeeded_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AuthConnection $connection): void {
            if ($connection->getAttribute('user_id') === null) {
                $connection->user_id = $connection->oracleTenant()->value('user_id');
            }
        });
    }

    /**
     * Keep the redundant owner column aligned with the tenant owner.
     */
    public function forTenant(OracleTenant $tenant): static
    {
        return $this->state(fn (): array => [
            'user_id' => $tenant->user_id,
            'oracle_tenant_id' => $tenant->id,
        ]);
    }
}
