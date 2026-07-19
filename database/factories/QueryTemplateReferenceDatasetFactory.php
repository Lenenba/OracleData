<?php

namespace Database\Factories;

use App\Enums\ReferenceScenarioType;
use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateReferenceDataset;
use App\Services\DatasetEquivalenceComparator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueryTemplateReferenceDataset>
 */
class QueryTemplateReferenceDatasetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $parameterKeys = ['minimum_amount'];
        $parameterHash = hash('sha256', json_encode(
            ['minimum_amount' => 1000],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $comparison = [
            'order_sensitive' => false,
            'duplicate_sensitive' => true,
            'compare_nulls' => true,
            'compare_schema' => true,
            'nested_order_sensitive' => true,
            'aggregates' => [],
        ];
        $fingerprint = app(DatasetEquivalenceComparator::class)->profile([
            ['InvoiceNumber' => 'INV-REFERENCE', 'Supplier' => null, 'InvoiceAmount' => 1000],
        ], $comparison);

        return [
            'query_template_id' => QueryTemplate::factory(),
            'query_template_version_id' => fn (array $attributes): int => QueryTemplate::query()
                ->findOrFail($attributes['query_template_id'])
                ->publishedVersion()
                ->sole()
                ->id,
            'oracle_tenant_id' => OracleTenant::factory(),
            'auth_connection_id' => function (array $attributes): int {
                $tenant = OracleTenant::query()->findOrFail($attributes['oracle_tenant_id']);

                return $tenant->authConnections()->value('id')
                    ?? AuthConnection::factory()->forTenant($tenant)->create()->id;
            },
            'captured_by_user_id' => fn (array $attributes): int => OracleTenant::query()
                ->findOrFail($attributes['oracle_tenant_id'])
                ->user_id,
            'name' => fake()->unique()->sentence(3),
            'scenario' => ReferenceScenarioType::Baseline,
            'parameter_hash' => $parameterHash,
            'parameter_keys' => $parameterKeys,
            'comparison_config' => $comparison,
            'fingerprint' => $fingerprint,
            'row_count' => $fingerprint['row_count'],
            'dataset_hash' => $fingerprint['dataset_hash'],
            'captured_at' => now(),
        ];
    }
}
