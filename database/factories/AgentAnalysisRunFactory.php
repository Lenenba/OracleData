<?php

namespace Database\Factories;

use App\Enums\AgentAnalysisRunStatus;
use App\Models\AgentAnalysisRun;
use App\Models\Query;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentAnalysisRun>
 */
class AgentAnalysisRunFactory extends Factory
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
            'query_id' => Query::factory()->agent(),
            'oracle_tenant_id' => null,
            'auth_connection_id' => null,
            'query_execution_id' => null,
            'status' => AgentAnalysisRunStatus::Queued,
            'iteration' => 0,
            'max_iterations' => 8,
            'oracle_calls_count' => 0,
            'result' => null,
            'row_count' => 0,
            'error_code' => null,
            'cancel_requested_at' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => AgentAnalysisRunStatus::Running,
            'iteration' => 1,
            'oracle_calls_count' => 1,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => AgentAnalysisRunStatus::Completed,
            'iteration' => 2,
            'oracle_calls_count' => 2,
            'result' => [
                'columns' => ['SupplierId', 'Amount'],
                'rows' => [['SupplierId' => 1, 'Amount' => 100]],
                'analysis' => 'Synthèse.',
                'oracleCalls' => [],
                'truncated' => false,
            ],
            'row_count' => 1,
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $errorCode = 'agent_error'): static
    {
        return $this->state(fn (): array => [
            'status' => AgentAnalysisRunStatus::Failed,
            'error_code' => $errorCode,
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => AgentAnalysisRunStatus::Cancelled,
            'cancel_requested_at' => now()->subSecond(),
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
        ]);
    }

    public function cancellationRequested(): static
    {
        return $this->state(fn (): array => [
            'status' => AgentAnalysisRunStatus::Running,
            'started_at' => now(),
            'cancel_requested_at' => now(),
        ]);
    }
}
