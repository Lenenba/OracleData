<?php

namespace Database\Factories;

use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryExecution>
 */
class QueryExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $finishedAt = now();

        return [
            'query_id' => Query::factory(),
            'user_id' => User::factory(),
            'oracle_tenant_id' => null,
            'auth_connection_id' => null,
            'status' => QueryExecution::STATUS_SUCCEEDED,
            'duration_ms' => fake()->numberBetween(50, 2000),
            'rows_count' => fake()->numberBetween(0, 500),
            'error_code' => null,
            'started_at' => $finishedAt->copy()->subSecond(),
            'finished_at' => $finishedAt,
        ];
    }

    public function failed(string $errorCode = 'oracle_error'): static
    {
        return $this->state(fn (): array => [
            'status' => QueryExecution::STATUS_FAILED,
            'error_code' => $errorCode,
            'rows_count' => 0,
        ]);
    }
}
