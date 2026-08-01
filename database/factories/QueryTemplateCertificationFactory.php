<?php

namespace Database\Factories;

use App\Models\QueryTemplate;
use App\Models\QueryTemplateCertification;
use App\Models\QueryTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryTemplateCertification> */
class QueryTemplateCertificationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'query_template_id' => QueryTemplate::factory(),
            'query_template_version_id' => function (array $attributes): int {
                return QueryTemplate::query()
                    ->whereKey($attributes['query_template_id'])
                    ->firstOrFail()
                    ->publishedVersion()
                    ->sole()
                    ->id;
            },
            'version_content_hash' => function (array $attributes): string {
                return QueryTemplateVersion::query()
                    ->whereKey($attributes['query_template_version_id'])
                    ->firstOrFail()
                    ->content_hash;
            },
            'active_slot' => QueryTemplateCertification::ACTIVE_SLOT,
            'certified_by_user_id' => null,
            'certified_at' => now(),
            'public_note' => fake()->optional()->sentence(),
            'revoked_by_user_id' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'lock_version' => 1,
        ];
    }
}
