<?php

namespace Database\Factories;

use App\Enums\SemanticLineageUsage;
use App\Models\Query;
use App\Models\QuerySemanticResource;
use App\Models\SemanticResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuerySemanticResource> */
class QuerySemanticResourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'query_id' => Query::factory(),
            'semantic_resource_id' => SemanticResource::factory(),
            'semantic_catalog_version_id' => null,
            'resource_key' => 'suppliers',
            'child_key' => '',
            'field_key' => '',
            'usage' => SemanticLineageUsage::Resource,
            'definition_hash' => hash('sha256', 'suppliers'),
            'captured_at' => now(),
        ];
    }
}
