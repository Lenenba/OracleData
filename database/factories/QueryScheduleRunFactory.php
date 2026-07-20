<?php

namespace Database\Factories;

use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryScheduleRun>
 */
class QueryScheduleRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_schedule_id' => QuerySchedule::factory(),
            'user_id' => User::factory(),
            'oracle_tenant_id' => null,
            'auth_connection_id' => null,
            'status' => QuerySchedule::STATUS_SUCCEEDED,
            'row_count' => fake()->numberBetween(0, 50),
            'duration_ms' => fake()->numberBetween(20, 2000),
            'error_code' => null,
            'ran_at' => now(),
        ];
    }

    public function failed(string $errorCode = 'oracle_error'): static
    {
        return $this->state(fn (): array => [
            'status' => QuerySchedule::STATUS_FAILED,
            'error_code' => $errorCode,
            'row_count' => 0,
        ]);
    }
}
