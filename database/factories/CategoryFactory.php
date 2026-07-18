<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'color' => fake()->hexColor(),
        ];
    }

    /**
     * Attach a French translation, the fallback locale of the platform.
     */
    public function withTranslation(string $locale = 'fr', ?string $name = null): static
    {
        return $this->afterCreating(function (Category $category) use ($locale, $name): void {
            CategoryTranslation::query()->create([
                'category_id' => $category->id,
                'locale' => $locale,
                'name' => $name ?? fake()->words(2, true),
                'description' => null,
            ]);
        });
    }
}
