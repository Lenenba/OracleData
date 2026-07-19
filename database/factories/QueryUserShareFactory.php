<?php

namespace Database\Factories;

use App\Enums\QuerySharePermission;
use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryUserShare> */
class QueryUserShareFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'query_id' => Query::factory()->restricted(),
            'shared_by_user_id' => User::factory(),
            'user_id' => User::factory(),
            'permission' => QuerySharePermission::VIEW,
            'status' => QueryUserShare::STATUS_ACCEPTED,
            'expires_at' => null,
            'respond_by' => null,
            'accepted_at' => now(),
            'declined_at' => null,
            'cancelled_at' => null,
            'revoked_at' => null,
        ];
    }

    public function permission(QuerySharePermission $permission): static
    {
        return $this->state(fn (): array => ['permission' => $permission]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => QueryUserShare::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => QueryUserShare::STATUS_PENDING,
            'respond_by' => now()->addWeek(),
            'accepted_at' => null,
            'declined_at' => null,
            'cancelled_at' => null,
            'revoked_at' => null,
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => [
            'status' => QueryUserShare::STATUS_DECLINED,
            'respond_by' => now()->addWeek(),
            'accepted_at' => null,
            'declined_at' => now(),
            'cancelled_at' => null,
            'revoked_at' => null,
        ]);
    }
}
