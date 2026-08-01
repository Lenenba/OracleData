<?php

use App\Models\PersonalApiToken;
use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Authorization: string, Accept: string}
 */
function queryApiHeaders(User $user): array
{
    $secret = PersonalApiToken::generateSecret();

    PersonalApiToken::query()->create([
        'user_id' => $user->id,
        'name' => 'Query API test',
        'token_hash' => PersonalApiToken::hashSecret($secret),
        'scopes' => [PersonalApiToken::SCOPE_READ_QUERIES],
        'is_active' => true,
        'requests_today' => 0,
        'daily_limit' => 100,
    ]);

    return [
        'Authorization' => 'Bearer '.$secret,
        'Accept' => 'application/json',
    ];
}

test('the query API uses the canonical access scope for accepted direct shares', function () {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();

    QueryUserShare::factory()->create([
        'query_id' => $query->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $reader->id,
    ]);

    $this->withHeaders(queryApiHeaders($reader))
        ->getJson(route('api.queries.show', $query))
        ->assertOk()
        ->assertJsonPath('id', $query->id);
});

test('the query API hides a restricted query while its direct share is pending', function () {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();

    QueryUserShare::factory()->pending()->create([
        'query_id' => $query->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $reader->id,
    ]);

    $this->withHeaders(queryApiHeaders($reader))
        ->getJson(route('api.queries.show', $query))
        ->assertNotFound();
});
