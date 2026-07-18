<?php

namespace Database\Factories;

use App\Models\QueryTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<QueryTemplate> */
class QueryTemplateFactory extends Factory
{
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
                'binding' => [
                    'kind' => 'filter',
                    'field' => 'InvoiceAmount',
                    'operator' => '>',
                ],
            ]],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
