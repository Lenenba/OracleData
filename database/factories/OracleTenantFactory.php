<?php

namespace Database\Factories;

use App\Models\OracleTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OracleTenant>
 */
class OracleTenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'key' => str_replace('-', '_', $key),
            'label' => fake()->company(),
            'base_url' => 'https://'.fake()->domainName(),
            'username' => fake()->userName(),
            'password' => 'secret',
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * Mark the tenant as the default Oracle environment.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
