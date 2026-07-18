<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Models\TagTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->bothify('Tag #### ??');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    /**
     * Attach an official localized label to the tag.
     */
    public function withTranslation(string $locale = 'fr', ?string $name = null): static
    {
        return $this->afterCreating(function (Tag $tag) use ($locale, $name): void {
            TagTranslation::query()->create([
                'tag_id' => $tag->id,
                'locale' => $locale,
                'name' => $name ?? fake()->words(2, true),
            ]);
        });
    }
}
