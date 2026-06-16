<?php

use App\Services\FusionClient;
use Illuminate\Support\Facades\Http;

function makeFusionClient(): FusionClient
{
    return new FusionClient(
        baseUrl: 'https://client-x.fa.oraclecloud.com',
        username: 'svc_user',
        password: 'secret',
    );
}

test('list() returns the items array from the Fusion envelope', function () {
    Http::fake([
        '*' => Http::response([
            'items' => [
                ['PersonId' => 1, 'DisplayName' => 'Jean Dupont'],
                ['PersonId' => 2, 'DisplayName' => 'Marie Martin'],
            ],
            'count' => 2,
            'hasMore' => true,
        ]),
    ]);

    $items = makeFusionClient()->list('/hcmRestApi/resources/11.13.18.05/workers', ['limit' => 25]);

    expect($items)->toHaveCount(2)
        ->and($items[0]['DisplayName'])->toBe('Jean Dupont');
});

test('get() returns the full decoded payload including metadata', function () {
    Http::fake([
        '*' => Http::response([
            'items' => [['PersonId' => 1]],
            'count' => 1,
            'hasMore' => false,
        ]),
    ]);

    $payload = makeFusionClient()->get('/hcmRestApi/resources/11.13.18.05/workers');

    expect($payload)->toHaveKeys(['items', 'count', 'hasMore'])
        ->and($payload['hasMore'])->toBeFalse();
});

test('get() targets the tenant base url with basic auth', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    makeFusionClient()->get('/hcmRestApi/resources/11.13.18.05/workers', ['limit' => 5]);

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://client-x.fa.oraclecloud.com')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('svc_user:secret'));
    });
});

test('get() wraps HTTP errors in a RuntimeException', function () {
    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    makeFusionClient()->get('/hcmRestApi/resources/11.13.18.05/workers');
})->throws(RuntimeException::class);

test('testConnection() returns true on success', function () {
    Http::fake(['*' => Http::response([], 200)]);

    expect(makeFusionClient()->testConnection())->toBeTrue();
});

test('testConnection() returns false on failure', function () {
    Http::fake(['*' => Http::response([], 500)]);

    expect(makeFusionClient()->testConnection())->toBeFalse();
});
