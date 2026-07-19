<?php

namespace Database\Factories;

use App\Models\QueryChangeRequest;
use App\Models\QueryChangeRequestComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueryChangeRequestComment> */
class QueryChangeRequestCommentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'query_change_request_id' => QueryChangeRequest::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
