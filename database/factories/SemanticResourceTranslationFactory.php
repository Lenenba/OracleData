<?php

namespace Database\Factories;

use App\Models\SemanticResource;
use App\Models\SemanticResourceTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticResourceTranslation> */
class SemanticResourceTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'semantic_resource_id' => SemanticResource::factory(),
            'locale' => 'fr',
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'synonyms' => [fake()->word()],
            'examples' => [fake()->sentence()],
        ];
    }
}
