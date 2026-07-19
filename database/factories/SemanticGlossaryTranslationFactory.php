<?php

namespace Database\Factories;

use App\Models\SemanticGlossaryTerm;
use App\Models\SemanticGlossaryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticGlossaryTranslation> */
class SemanticGlossaryTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'semantic_glossary_term_id' => SemanticGlossaryTerm::factory(),
            'locale' => 'fr',
            'term' => fake()->words(2, true),
            'definition' => fake()->sentence(),
            'synonyms' => [fake()->word()],
            'forbidden_terms' => [],
            'examples' => [fake()->sentence()],
        ];
    }
}
