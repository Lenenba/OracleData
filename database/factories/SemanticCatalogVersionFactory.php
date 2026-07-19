<?php

namespace Database\Factories;

use App\Enums\SemanticCatalogVersionStatus;
use App\Models\SemanticCatalogVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SemanticCatalogVersion> */
class SemanticCatalogVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $catalog = [
            'schema_version' => 1,
            'resources' => [],
            'relations' => [],
            'glossary' => [],
        ];

        return [
            'version_number' => fake()->unique()->numberBetween(1, 1_000_000),
            'status' => SemanticCatalogVersionStatus::Draft,
            'open_slot' => SemanticCatalogVersion::OPEN_SLOT,
            'published_slot' => null,
            'catalog' => $catalog,
            'content_hash' => SemanticCatalogVersion::contentHash($catalog),
            'change_summary' => fake()->sentence(),
            'created_by_user_id' => null,
            'submitted_by_user_id' => null,
            'published_by_user_id' => null,
            'submitted_at' => null,
            'published_at' => null,
            'lock_version' => 1,
        ];
    }
}
