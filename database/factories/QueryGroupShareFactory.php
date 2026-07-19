<?php

namespace Database\Factories;

use App\Enums\QuerySharePermission;
use App\Models\Group;
use App\Models\Query;
use App\Models\QueryGroupShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryGroupShare> */
class QueryGroupShareFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'query_id' => Query::factory()->restricted(),
            'group_id' => Group::factory(),
            'group_name' => fake()->words(3, true),
            'shared_by_user_id' => User::factory(),
            'permission' => QuerySharePermission::VIEW,
            'status' => QueryGroupShare::STATUS_ACCEPTED,
            'expires_at' => null,
            'accepted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function permission(QuerySharePermission $permission): static
    {
        return $this->state(fn (): array => ['permission' => $permission]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => QueryGroupShare::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
