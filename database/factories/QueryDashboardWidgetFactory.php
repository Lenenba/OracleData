<?php

namespace Database\Factories;

use App\Models\Query;
use App\Models\QueryDashboard;
use App\Models\QueryDashboardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryDashboardWidget> */
class QueryDashboardWidgetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'dashboard_id' => QueryDashboard::factory(),
            'query_id' => function (array $attributes): Factory {
                $dashboard = QueryDashboard::query()
                    ->whereKey($attributes['dashboard_id'])
                    ->firstOrFail();

                return Query::factory()->for($dashboard->user);
            },
            'widget_type' => fake()->randomElement(['kpi', 'table', 'chart']),
            'title' => fake()->optional()->sentence(3),
            'position' => fake()->numberBetween(0, 100),
            'widget_options' => null,
        ];
    }
}
