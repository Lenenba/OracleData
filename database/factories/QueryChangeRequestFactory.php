<?php

namespace Database\Factories;

use App\Enums\QueryChangeRequestStatus;
use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryChangeRequest> */
class QueryChangeRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'query_id' => Query::factory()->organization(),
            'requested_by_user_id' => User::factory(),
            'title' => fake()->sentence(5),
            'status' => QueryChangeRequestStatus::PENDING,
            'status_changed_by_user_id' => null,
            'status_changed_at' => null,
        ];
    }

    public function accepted(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'status' => QueryChangeRequestStatus::ACCEPTED,
            'status_changed_by_user_id' => $actor->id ?? User::factory(),
            'status_changed_at' => now(),
        ]);
    }

    public function completed(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'status' => QueryChangeRequestStatus::COMPLETED,
            'status_changed_by_user_id' => $actor->id ?? User::factory(),
            'status_changed_at' => now(),
        ]);
    }
}
