<?php

namespace Database\Factories;

use App\Models\SemanticField;
use App\Models\SemanticFieldTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticFieldTranslation> */
class SemanticFieldTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'semantic_field_id' => SemanticField::factory(),
            'locale' => 'fr',
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'synonyms' => [fake()->word()],
            'examples' => [fake()->sentence()],
        ];
    }
}
