<?php

namespace Database\Factories;

use App\Enums\GroupRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Group> */
class GroupFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Group $group): void {
            $group->members()->syncWithoutDetaching([
                $group->owner_id => ['role' => GroupRole::OWNER->value],
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
