<?php

use App\Models\Query;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config()->set('services.anthropic', [
        'api_key' => 'sk-test',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-opus-4-8',
        'version' => '2023-06-01',
    ]);
    config()->set('fusion.default', 'client_x');
    config()->set('fusion.tenants', [
        'client_x' => [
            'label' => 'Client X',
            'base_url' => 'https://client-x.fa.oraclecloud.com',
            'username' => 'svc_x',
            'password' => 'secret_x',
        ],
    ]);

    // Clear rate limiter state between tests.
    RateLimiter::clear('throttle');
});

test('preview is accessible within the limit', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'mode' => 'clarify',
                'question' => '?',
            ])]],
            'stop_reason' => 'end_turn',
        ]),
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('queries.preview'), [
            'intent' => 'liste des fournisseurs',
            'tenant' => 'client_x',
        ])
        ->assertOk();
});

test('run is accessible within the limit', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0])]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();
});

test('preview is throttled after 15 requests per minute', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'mode' => 'clarify',
                'question' => '?',
            ])]],
            'stop_reason' => 'end_turn',
        ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // 15 requests should succeed.
    for ($i = 0; $i < 15; $i++) {
        $this->postJson(route('queries.preview'), [
            'intent' => 'test',
            'tenant' => 'client_x',
        ])->assertOk();
    }

    // The 16th request must be throttled.
    $this->postJson(route('queries.preview'), [
        'intent' => 'test',
        'tenant' => 'client_x',
    ])->assertTooManyRequests();
});

test('run is throttled after 15 requests per minute', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0])]);

    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();
    $this->actingAs($user);

    for ($i = 0; $i < 15; $i++) {
        $this->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
            ->assertOk();
    }

    $this->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertTooManyRequests();
});

test('direct-preview has its own higher limit for the live builder', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0])]);

    $this->actingAs(User::factory()->create());

    // 60 requêtes passent (aperçu live débouncé, sans LLM)…
    for ($i = 0; $i < 60; $i++) {
        $this->postJson(route('queries.direct-preview'), [
            'resource_key' => 'suppliers',
            'tenant' => 'client_x',
        ])->assertOk();
    }

    // …la 61e est refusée.
    $this->postJson(route('queries.direct-preview'), [
        'resource_key' => 'suppliers',
        'tenant' => 'client_x',
    ])->assertTooManyRequests();
});

test('throttle is per user — different users have independent limits', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0])]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $queryA = Query::factory()->for($userA)->create();
    $queryB = Query::factory()->for($userB)->create();

    // Exhaust userA's quota.
    $this->actingAs($userA);
    for ($i = 0; $i < 15; $i++) {
        $this->postJson(route('queries.run', $queryA), ['tenant' => 'client_x'])->assertOk();
    }
    $this->postJson(route('queries.run', $queryA), ['tenant' => 'client_x'])->assertTooManyRequests();

    // userB should still have a full quota.
    $this->actingAs($userB);
    $this->postJson(route('queries.run', $queryB), ['tenant' => 'client_x'])->assertOk();
});
