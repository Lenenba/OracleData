<?php

namespace Database\Factories;

use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
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
            'query_template_id' => null,
            'query_template_version_id' => null,
            'user_id' => User::factory(),
            'oracle_tenant_id' => null,
            'auth_connection_id' => null,
            'source_type' => QueryExecution::SOURCE_SAVED_QUERY,
            'purpose' => QueryExecution::PURPOSE_RUN,
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

    public function forQueryTemplate(?QueryTemplate $template = null): static
    {
        return $this->state(function () use ($template): array {
            $resolvedTemplate = $template ?? QueryTemplate::factory()->create();

            return [
                'query_id' => null,
                'query_template_id' => $resolvedTemplate->id,
                'query_template_version_id' => $resolvedTemplate->published_version_id,
                'source_type' => QueryExecution::SOURCE_QUERY_TEMPLATE,
            ];
        });
    }

    public function preview(): static
    {
        return $this->state(fn (): array => [
            'purpose' => QueryExecution::PURPOSE_PREVIEW,
        ]);
    }
}
