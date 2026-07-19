<?php

namespace Database\Factories;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Models\SemanticGlossaryTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticGlossaryTerm> */
class SemanticGlossaryTermFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'term_key' => fake()->unique()->lexify('term_????????'),
            'domain' => fake()->randomElement(['Procurement', 'Finance', 'HCM', 'Projects']),
            'classification' => SemanticClassification::Unclassified,
            'data_category' => SemanticDataCategory::General,
            'is_active' => true,
            'lock_version' => 1,
        ];
    }
}
