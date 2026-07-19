<?php

namespace Database\Factories;

use App\Enums\QueryAccessLevel;
use App\Models\Query;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Query>
 */
class QueryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
            'tenant_key' => 'client_x',
            'mode' => 'single',
            'parameters' => ['limit' => 25],
            'access_level' => QueryAccessLevel::PRIVATE,
            'query_template_version_id' => null,
        ];
    }

    /**
     * Indicate that the query is a multi-resource analysis run by the agent.
     */
    public function agent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mode' => 'agent',
            'resource_path' => null,
            'parameters' => null,
            'description' => 'Lier les fournisseurs et les factures et analyser le total facturé.',
        ]);
    }

    /**
     * Indicate that the query is private to its owner.
     */
    public function private(): static
    {
        return $this->state(fn (): array => [
            'access_level' => QueryAccessLevel::PRIVATE,
        ]);
    }

    /**
     * Indicate that the query is shared only with selected recipients.
     */
    public function restricted(): static
    {
        return $this->state(fn (): array => [
            'access_level' => QueryAccessLevel::RESTRICTED,
        ]);
    }

    /**
     * Indicate that the query is shared with all authenticated users.
     */
    public function organization(): static
    {
        return $this->state(fn (): array => [
            'access_level' => QueryAccessLevel::ORGANIZATION,
        ]);
    }

    /**
     * Backward-compatible factory alias for historical shared-query tests.
     */
    public function shared(): static
    {
        return $this->organization();
    }
}
