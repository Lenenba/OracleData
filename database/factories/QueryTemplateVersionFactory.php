<?php

namespace Database\Factories;

use App\Enums\QueryTemplateVersionStatus;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryTemplateVersion> */
class QueryTemplateVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $definition = QueryTemplate::factory()->make()->definitionSnapshot();
        $translations = [];

        return [
            'query_template_id' => QueryTemplate::factory(),
            'version_number' => 2,
            'status' => QueryTemplateVersionStatus::DRAFT,
            'open_slot' => 1,
            'definition' => $definition,
            'translations' => $translations,
            'content_hash' => QueryTemplateVersion::contentHash($definition, $translations),
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
