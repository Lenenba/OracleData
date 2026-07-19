<?php

namespace Database\Factories;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Enums\SemanticSqlMappingStatus;
use App\Models\SemanticField;
use App\Models\SemanticResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticField> */
class SemanticFieldFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'semantic_resource_id' => SemanticResource::factory(),
            'child_key' => '',
            'source_name' => fake()->unique()->lexify('Field??????'),
            'data_type' => fake()->randomElement(['string', 'integer', 'number', 'date', 'boolean']),
            'classification' => SemanticClassification::Unclassified,
            'data_category' => SemanticDataCategory::General,
            'sql_expression' => null,
            'sql_mapping_status' => SemanticSqlMappingStatus::Unmapped,
            'is_nullable' => true,
            'is_updatable' => false,
            'is_active' => true,
            'last_seen_at' => now(),
            'lock_version' => 1,
        ];
    }
}
