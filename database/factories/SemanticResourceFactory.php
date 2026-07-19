<?php

namespace Database\Factories;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Enums\SemanticSqlMappingStatus;
use App\Models\SemanticResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticResource> */
class SemanticResourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $resourceKey = fake()->unique()->lexify('resource_??????');

        return [
            'resource_key' => $resourceKey,
            'source_name' => str_replace('_', '', ucwords($resourceKey, '_')),
            'domain' => fake()->randomElement(['Procurement', 'Finance', 'HCM', 'Projects']),
            'api_path' => "/fscmRestApi/resources/11.13.18.05/{$resourceKey}",
            'business_owner_user_id' => null,
            'technical_owner_user_id' => null,
            'classification' => SemanticClassification::Unclassified,
            'data_category' => SemanticDataCategory::General,
            'sql_table' => null,
            'sql_alias' => null,
            'sql_mapping_status' => SemanticSqlMappingStatus::Unmapped,
            'mapping_notes' => null,
            'is_active' => true,
            'lock_version' => 1,
        ];
    }
}
