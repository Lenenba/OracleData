<?php

namespace Database\Factories;

use App\Enums\DataQualityRunPurpose;
use App\Enums\DataQualityRunStatus;
use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\QueryExecution;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateReferenceDataset;
use App\Models\QueryTemplateValidationRun;
use App\Models\QueryTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryTemplateValidationRun>
 */
class QueryTemplateValidationRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $durationMs = fake()->numberBetween(50, 2000);
        $finishedAt = now();
        $startedAt = $finishedAt->copy()->subMilliseconds($durationMs);

        return [
            'query_template_id' => QueryTemplate::factory(),
            'query_template_version_id' => fn (array $attributes): int => QueryTemplate::query()
                ->whereKey($attributes['query_template_id'])
                ->firstOrFail()
                ->publishedVersion()
                ->sole()
                ->id,
            'oracle_tenant_id' => OracleTenant::factory(),
            'auth_connection_id' => function (array $attributes): int {
                $tenant = OracleTenant::query()
                    ->whereKey($attributes['oracle_tenant_id'])
                    ->firstOrFail();

                return $tenant->authConnections()->value('id')
                    ?? AuthConnection::factory()->forTenant($tenant)->create()->id;
            },
            'run_by_user_id' => fn (array $attributes): int => OracleTenant::query()
                ->whereKey($attributes['oracle_tenant_id'])
                ->firstOrFail()
                ->user_id,
            'query_template_reference_dataset_id' => fn (array $attributes): int => QueryTemplateReferenceDataset::factory()->create([
                'query_template_id' => $attributes['query_template_id'],
                'query_template_version_id' => $attributes['query_template_version_id'],
                'oracle_tenant_id' => $attributes['oracle_tenant_id'],
                'auth_connection_id' => $attributes['auth_connection_id'],
                'captured_by_user_id' => $attributes['run_by_user_id'],
            ])->id,
            'purpose' => DataQualityRunPurpose::Manual,
            'status' => DataQualityRunStatus::Passed,
            'version_content_hash' => fn (array $attributes): string => QueryTemplateVersion::query()
                ->whereKey($attributes['query_template_version_id'])
                ->firstOrFail()
                ->content_hash,
            'rules_hash' => fn (array $attributes): string => QueryTemplateVersion::qualityRulesHash(
                QueryTemplateVersion::query()
                    ->whereKey($attributes['query_template_version_id'])
                    ->firstOrFail()
                    ->quality_rules ?? [],
            ),
            'parameter_hash' => fn (array $attributes): string => QueryTemplateReferenceDataset::query()
                ->whereKey($attributes['query_template_reference_dataset_id'])
                ->firstOrFail()
                ->parameter_hash,
            'assertion_results' => [[
                'index' => 0,
                'type' => 'non_empty',
                'required' => true,
                'passed' => true,
                'code' => 'passed',
                'metrics' => ['row_count' => 1],
            ]],
            'score' => 100,
            'duration_ms' => $durationMs,
            'row_count' => fn (array $attributes): int => QueryTemplateReferenceDataset::query()
                ->whereKey($attributes['query_template_reference_dataset_id'])
                ->firstOrFail()
                ->row_count,
            'error_code' => null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'query_execution_id' => fn (array $attributes): int => QueryExecution::factory()->create([
                'query_id' => null,
                'query_template_id' => $attributes['query_template_id'],
                'query_template_version_id' => $attributes['query_template_version_id'],
                'user_id' => $attributes['run_by_user_id'],
                'oracle_tenant_id' => $attributes['oracle_tenant_id'],
                'auth_connection_id' => $attributes['auth_connection_id'],
                'source_type' => QueryExecution::SOURCE_QUERY_TEMPLATE,
                'purpose' => QueryExecution::PURPOSE_QUALITY_VALIDATION,
                'status' => QueryExecution::STATUS_SUCCEEDED,
                'duration_ms' => $attributes['duration_ms'],
                'rows_count' => $attributes['row_count'],
                'error_code' => null,
                'started_at' => $attributes['started_at'],
                'finished_at' => $attributes['finished_at'],
            ])->id,
        ];
    }
}
