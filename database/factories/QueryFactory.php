<?php

namespace Database\Factories;

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
            'parameters' => ['limit' => 25],
            'visibility' => 'private',
        ];
    }

    /**
     * Indicate that the query is private to its owner.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => 'private',
        ]);
    }

    /**
     * Indicate that the query is shared with all authenticated users.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => 'shared',
        ]);
    }
}
