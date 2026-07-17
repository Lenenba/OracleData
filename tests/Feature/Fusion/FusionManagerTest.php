<?php

use App\Models\User;
use App\Services\FusionClient;
use App\Services\FusionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('tenant resolves a FusionClient from an owned database connection', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user, ['key' => 'client_x']);

    expect(app(FusionManager::class)->forUser($user)->tenant('client_x'))
        ->toBeInstanceOf(FusionClient::class);
});

test('tenant throws for an unknown or unowned tenant', function () {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    createOracleTenantFor($owner, ['key' => 'private_tenant']);

    app(FusionManager::class)->forUser($reader)->tenant('private_tenant');
})->throws(InvalidArgumentException::class);

test('configuration-file tenants are no longer resolver inputs', function () {
    config()->set('fusion.tenants', [
        'legacy' => [
            'label' => 'Legacy',
            'base_url' => 'https://legacy.fa.oraclecloud.com',
            'username' => 'legacy',
            'password' => 'legacy',
        ],
    ]);

    expect(app(FusionManager::class)->forUser(User::factory()->create())->available())
        ->toBe([]);
});

test('default targets the owned default tenant with its active basic credentials', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    $user = User::factory()->create();
    createOracleTenantFor($user, [
        'key' => 'client_z',
        'label' => 'Client Z',
        'base_url' => 'https://client-z.fa.oraclecloud.com',
        'is_default' => true,
    ], [
        'identifier' => 'svc_z',
        'secret' => 'secret_z',
    ]);

    $manager = app(FusionManager::class)->forUser($user);

    expect($manager->defaultKey())->toBe('client_z')
        ->and($manager->available())->toBe(['client_z' => 'Client Z']);

    $manager->default()->get('/hcmRestApi/resources/11.13.18.05/workers');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://client-z.fa.oraclecloud.com')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('svc_z:secret_z')));
});

test('the same key resolves independently for different users', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    $first = User::factory()->create();
    $second = User::factory()->create();
    createOracleTenantFor($first, [
        'key' => 'production',
        'base_url' => 'https://first.fa.oraclecloud.com',
    ], [
        'identifier' => 'first_user',
        'secret' => 'first_secret',
    ]);
    createOracleTenantFor($second, [
        'key' => 'production',
        'base_url' => 'https://second.fa.oraclecloud.com',
    ], [
        'identifier' => 'second_user',
        'secret' => 'second_secret',
    ]);

    app(FusionManager::class)->forUser($first)
        ->tenant('production')
        ->get('/hcmRestApi/resources/11.13.18.05/workers');
    app(FusionManager::class)->forUser($second)
        ->tenant('production')
        ->get('/hcmRestApi/resources/11.13.18.05/workers');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://first.fa.oraclecloud.com')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('first_user:first_secret')));
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://second.fa.oraclecloud.com')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('second_user:second_secret')));
});

test('available exposes only active owned tenants backed by verified active connections', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    createOracleTenantFor($user, ['key' => 'available', 'label' => 'Available']);
    createOracleTenantFor($user, ['key' => 'inactive_tenant', 'is_active' => false]);
    createOracleTenantFor($user, ['key' => 'inactive_connection'], ['is_active' => false]);
    createOracleTenantFor($user, ['key' => 'unverified_connection'], ['verified_at' => null]);
    createOracleTenantFor($user, ['key' => 'unsupported_connection'], ['auth_type' => 'oauth2']);
    createOracleTenantFor($other, ['key' => 'unowned']);

    $manager = app(FusionManager::class)->forUser($user);

    expect($manager->available())->toBe(['available' => 'Available'])
        ->and($manager->keys())->toBe(['available'])
        ->and($manager->has('available'))->toBeTrue()
        ->and($manager->has('inactive_tenant'))->toBeFalse()
        ->and($manager->has('inactive_connection'))->toBeFalse()
        ->and($manager->has('unverified_connection'))->toBeFalse()
        ->and($manager->has('unsupported_connection'))->toBeFalse()
        ->and($manager->has('unowned'))->toBeFalse();
});

test('details returns non-sensitive management metadata for all owned connections', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user, [
        'key' => 'client_z',
        'label' => 'Client Z',
    ], [
        'identifier' => 'svc_z',
        'secret' => 'do-not-expose',
    ]);

    $details = collect(app(FusionManager::class)->forUser($user)->details())
        ->firstWhere('key', 'client_z');

    expect($details)->not->toBeNull()
        ->and($details['id'])->toBe($tenant->id)
        ->and($details['source'])->toBe('database')
        ->and($details['username'])->toBe('svc_z')
        ->and($details['connection_count'])->toBe(1)
        ->and($details)->not->toHaveKeys(['password', 'secret']);
});

test('default throws when the user has no active connection', function () {
    app(FusionManager::class)->forUser(User::factory()->create())->default();
})->throws(InvalidArgumentException::class, 'Aucune connexion Oracle active');

test('the manager is scoped in the application container', function () {
    expect(app(FusionManager::class))->toBe(app(FusionManager::class));
});

test('database tenants are memoized until explicitly forgotten', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user, ['key' => 'memoized']);
    $manager = app(FusionManager::class)->forUser($user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $manager->available();
    $manager->details();
    $queryCountAfterResolution = count(DB::getQueryLog());

    $manager->keys();
    $manager->label('memoized');
    $manager->details();

    expect(count(DB::getQueryLog()))->toBe($queryCountAfterResolution);

    createOracleTenantFor($user, ['key' => 'added_later']);

    expect($manager->available())->not->toHaveKey('added_later');

    $manager->forgetResolvedTenants();

    expect($manager->available())->toHaveKey('added_later');
});
