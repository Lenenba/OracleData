<?php

namespace Database\Factories;

use App\Models\SemanticRelation;
use App\Models\SemanticRelationTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticRelationTranslation> */
class SemanticRelationTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'semantic_relation_id' => SemanticRelation::factory(),
            'locale' => 'fr',
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }
}
