<?php

namespace Database\Factories;

use App\Enums\QueryExportStatus;
use App\Models\Query;
use App\Models\QueryExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryExport>
 */
class QueryExportFactory extends Factory
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
            'auth_connection_id' => null,
            'status' => QueryExportStatus::Queued,
            'format' => 'csv',
            'row_count' => 0,
            'max_rows' => QueryExport::MAX_ROWS,
            'truncated' => false,
            'file_path' => null,
            'file_size' => null,
            'error_code' => null,
            'cancel_requested_at' => null,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'expires_at' => null,
        ];
    }

    public function completed(string $filePath = 'exports/example.csv'): static
    {
        return $this->state(fn (): array => [
            'status' => QueryExportStatus::Completed,
            'row_count' => 3,
            'file_path' => $filePath,
            'file_size' => 128,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
            'expires_at' => now()->addDays(QueryExport::RETENTION_DAYS),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => QueryExportStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function failed(string $errorCode = 'export_error'): static
    {
        return $this->state(fn (): array => [
            'status' => QueryExportStatus::Failed,
            'error_code' => $errorCode,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->completed()->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
