<?php

namespace Database\Factories;

use App\Models\OracleResourceSchemaSnapshot;
use App\Models\OracleTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OracleResourceSchemaSnapshot> */
class OracleResourceSchemaSnapshotFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $attributes = [
            ['name' => 'SupplierId', 'type' => 'integer'],
            ['name' => 'SupplierName', 'type' => 'string'],
        ];

        return [
            'oracle_tenant_id' => OracleTenant::factory(),
            'previous_snapshot_id' => null,
            'triggered_by_user_id' => null,
            'resource_key' => 'suppliers',
            'child' => '',
            'source' => 'describe',
            'title' => 'Suppliers',
            'fields' => ['SupplierId', 'SupplierName'],
            'attributes' => $attributes,
            'schema_hash' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
            'diff' => [
                'added' => ['SupplierId', 'SupplierName'],
                'removed' => [],
                'changed' => [],
            ],
            'synced_at' => now(),
        ];
    }
}
