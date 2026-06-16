<?php

use App\Services\FusionClient;
use App\Services\FusionManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('fusion.default', 'client_x');
    config()->set('fusion.tenants', [
        'client_x' => [
            'label' => 'Client X (Production)',
            'base_url' => 'https://client-x.fa.oraclecloud.com',
            'username' => 'svc_x',
            'password' => 'secret_x',
        ],
        'client_y' => [
            'label' => 'Client Y',
            'base_url' => 'https://client-y.fa.oraclecloud.com',
            'username' => 'svc_y',
            'password' => 'secret_y',
        ],
    ]);
});

test('tenant() resolves a FusionClient for a configured tenant', function () {
    expect(app(FusionManager::class)->tenant('client_x'))->toBeInstanceOf(FusionClient::class);
});

test('tenant() throws for an unknown tenant', function () {
    app(FusionManager::class)->tenant('unknown');
})->throws(InvalidArgumentException::class);

test('default() resolves the configured default tenant', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    app(FusionManager::class)->default()->get('/hcmRestApi/resources/11.13.18.05/workers');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://client-x.fa.oraclecloud.com'));
});

test('different tenants target different base urls', function () {
    Http::fake([
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [['t' => 'x']]]),
        'client-y.fa.oraclecloud.com/*' => Http::response(['items' => [['t' => 'y']]]),
    ]);

    $manager = app(FusionManager::class);
    $x = $manager->tenant('client_x')->list('/hcmRestApi/resources/11.13.18.05/workers');
    $y = $manager->tenant('client_y')->list('/hcmRestApi/resources/11.13.18.05/workers');

    expect($x[0]['t'])->toBe('x')
        ->and($y[0]['t'])->toBe('y');
});

test('available() returns configured tenant keys and labels', function () {
    expect(app(FusionManager::class)->available())->toBe([
        'client_x' => 'Client X (Production)',
        'client_y' => 'Client Y',
    ]);
});

test('has() reflects configured tenants', function () {
    $manager = app(FusionManager::class);

    expect($manager->has('client_x'))->toBeTrue()
        ->and($manager->has('nope'))->toBeFalse();
});

test('the manager is registered as a singleton', function () {
    expect(app(FusionManager::class))->toBe(app(FusionManager::class));
});
