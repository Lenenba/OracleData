<?php

namespace Database\Factories;

use App\Models\QueryAlert;
use App\Models\QueryAlertEvent;
use App\Models\QueryScheduleRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryAlertEvent>
 */
class QueryAlertEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_alert_id' => QueryAlert::factory(),
            'query_schedule_run_id' => QueryScheduleRun::factory(),
            'user_id' => User::factory(),
            'observed_value' => fake()->numberBetween(0, 100),
            'triggered_at' => now(),
        ];
    }
}
