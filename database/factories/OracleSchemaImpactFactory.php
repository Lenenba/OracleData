<?php

namespace Database\Factories;

use App\Enums\OracleSchemaImpactKind;
use App\Enums\OracleSchemaImpactSeverity;
use App\Enums\OracleSchemaImpactStatus;
use App\Models\OracleResourceSchemaSnapshot;
use App\Models\OracleSchemaImpact;
use App\Models\OracleTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OracleSchemaImpact> */
class OracleSchemaImpactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'oracle_tenant_id' => OracleTenant::factory(),
            'oracle_resource_schema_snapshot_id' => fn (array $attributes): int => OracleResourceSchemaSnapshot::factory()->create([
                'oracle_tenant_id' => $attributes['oracle_tenant_id'],
            ])->id,
            'semantic_catalog_version_id' => null,
            'query_id' => null,
            'query_template_version_id' => null,
            'resource_key' => 'suppliers',
            'child_key' => '',
            'kind' => OracleSchemaImpactKind::FieldChanged,
            'severity' => OracleSchemaImpactSeverity::Review,
            'status' => OracleSchemaImpactStatus::Open,
            'affected_fields' => ['SupplierId'],
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'detected_at' => now(),
            'acknowledged_by_user_id' => null,
            'acknowledged_at' => null,
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_notes' => null,
        ];
    }
}
