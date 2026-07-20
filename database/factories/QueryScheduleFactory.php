<?php

namespace Database\Factories;

use App\Enums\ScheduleFrequency;
use App\Models\Query;
use App\Models\QuerySchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuerySchedule>
 */
class QueryScheduleFactory extends Factory
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
            'query_id' => Query::factory(),
            'oracle_tenant_id' => null,
            'name' => fake()->sentence(3),
            'tenant_key' => 'client_x',
            'frequency' => ScheduleFrequency::Daily,
            'time_of_day' => '08:00',
            'day_of_week' => null,
            'timezone' => 'UTC',
            'is_active' => true,
            'last_run_at' => null,
            'next_run_at' => now()->addDay(),
            'last_status' => null,
        ];
    }

    public function hourly(): static
    {
        return $this->state(fn (): array => [
            'frequency' => ScheduleFrequency::Hourly,
            'time_of_day' => null,
            'day_of_week' => null,
        ]);
    }

    public function weekly(int $dayOfWeek = 1): static
    {
        return $this->state(fn (): array => [
            'frequency' => ScheduleFrequency::Weekly,
            'time_of_day' => '08:00',
            'day_of_week' => $dayOfWeek,
        ]);
    }

    public function due(): static
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'next_run_at' => now()->subMinute(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'next_run_at' => null,
        ]);
    }
}
