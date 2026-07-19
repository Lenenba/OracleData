<?php

namespace Database\Factories;

use App\Enums\QueryTemplateGovernanceStatus;
use App\Enums\QueryTemplateVersionStatus;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @extends Factory<QueryTemplate> */
class QueryTemplateFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (QueryTemplate $template): void {
            if (! Schema::hasTable('query_template_versions') || $template->published_version_id !== null) {
                return;
            }

            $template->loadMissing('translations');
            $definition = $template->definitionSnapshot();
            $translations = $template->translationSnapshot();
            $contentHash = QueryTemplateVersion::contentHash($definition, $translations);
            $publishedAt = $template->updated_at ?? now();
            $version = QueryTemplateVersion::query()->create([
                'query_template_id' => $template->id,
                'version_number' => 1,
                'status' => QueryTemplateVersionStatus::PUBLISHED,
                'open_slot' => null,
                'definition' => $definition,
                'translations' => $translations,
                'content_hash' => $contentHash,
                'submitted_at' => $publishedAt,
                'published_at' => $publishedAt,
            ]);
            $status = $template->is_active
                ? QueryTemplateGovernanceStatus::PUBLISHED
                : QueryTemplateGovernanceStatus::ARCHIVED;

            $template->applyGovernanceProjection([
                'governance_status' => $status,
                'published_version_id' => $version->id,
                'published_at' => $publishedAt,
                'archived_at' => $status === QueryTemplateGovernanceStatus::ARCHIVED ? $publishedAt : null,
            ]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(4);

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'description' => fake()->sentence(),
            'category_id' => null,
            'resource_key' => 'invoices',
            'resource_path' => '/fscmRestApi/resources/11.13.18.05/invoices',
            'parameters' => [
                'resource_key' => 'invoices',
                'fields' => 'InvoiceNumber,Supplier,InvoiceAmount',
                'limit' => 50,
            ],
            'parameter_definitions' => [[
                'key' => 'minimum_amount',
                'label' => 'Montant minimum',
                'type' => 'number',
                'required' => true,
                'default' => 1000,
                'min' => 0,
                'audit' => ['mode' => 'masked'],
                'binding' => [
                    'kind' => 'filter',
                    'field' => 'InvoiceAmount',
                    'operator' => '>',
                ],
            ]],
            'is_active' => true,
            'governance_status' => QueryTemplateGovernanceStatus::PUBLISHED,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
            'governance_status' => QueryTemplateGovernanceStatus::ARCHIVED,
        ]);
    }
}
