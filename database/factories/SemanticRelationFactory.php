<?php

namespace Database\Factories;

use App\Enums\SemanticCardinality;
use App\Enums\SemanticRelationKind;
use App\Enums\SemanticRelationStatus;
use App\Models\SemanticRelation;
use App\Models\SemanticResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticRelation> */
class SemanticRelationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source_resource_id' => SemanticResource::factory(),
            'target_resource_id' => SemanticResource::factory(),
            'relation_key' => fake()->unique()->lexify('relation_????????'),
            'kind' => SemanticRelationKind::Join,
            'target_key' => fake()->lexify('target_??????'),
            'source_field' => 'SourceId',
            'target_field' => 'TargetId',
            'cardinality' => SemanticCardinality::OneToMany,
            'sql_table' => null,
            'sql_alias' => null,
            'sql_join' => null,
            'status' => SemanticRelationStatus::Draft,
            'is_active' => true,
            'lock_version' => 1,
        ];
    }
}
