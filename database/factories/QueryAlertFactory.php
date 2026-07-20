<?php

namespace Database\Factories;

use App\Enums\AlertCondition;
use App\Models\QueryAlert;
use App\Models\QuerySchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryAlert>
 */
class QueryAlertFactory extends Factory
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
            'name' => fake()->sentence(2),
            'condition' => AlertCondition::RowCountAbove,
            'threshold' => 10,
            'is_active' => true,
            'last_triggered_at' => null,
        ];
    }

    public function condition(AlertCondition $condition, ?int $threshold = null): static
    {
        return $this->state(fn (): array => [
            'condition' => $condition,
            'threshold' => $condition->requiresThreshold() ? ($threshold ?? 10) : null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
