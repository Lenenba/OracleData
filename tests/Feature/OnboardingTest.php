<?php

use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login from onboarding', function () {
    $this->get(route('onboarding.connection'))
        ->assertRedirect(route('login'));

    $this->post(route('onboarding.connection.store'), [])
        ->assertRedirect(route('login'));
});

test('a user without onboarding can open the first connection step', function () {
    $user = User::factory()->withoutOnboarding()->create();

    $this->actingAs($user)
        ->get(route('onboarding.connection'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/connection')
        );
});

test('product routes redirect a user who has not completed onboarding', function () {
    $user = User::factory()->withoutOnboarding()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.connection'));
});

test('the historical onboarding timestamp unlocks product routes without a relation query', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('a completed user with an active connection skips onboarding', function () {
    $user = createConnectedUser();

    $this->actingAs($user)
        ->get(route('onboarding.connection'))
        ->assertRedirect(route('dashboard'));
});

test('successful onboarding tests credentials and persists the first owned active connection', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->withoutOnboarding()->create();

    $this->actingAs($user)
        ->post(route('onboarding.connection.store'), [
            'key' => 'production_ca',
            'label' => 'Production Canada',
            'base_url' => 'https://production-ca.fa.oraclecloud.com/',
            'username' => 'svc_onboarding',
            'password' => 'first-secret',
            'is_default' => '0',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $tenant = OracleTenant::whereBelongsTo($user)->sole();
    $connection = AuthConnection::whereBelongsTo($user)->sole();

    expect($user->refresh()->hasCompletedOnboarding())->toBeTrue()
        ->and($tenant->user_id)->toBe($user->id)
        ->and($tenant->key)->toBe('production_ca')
        ->and($tenant->base_url)->toBe('https://production-ca.fa.oraclecloud.com')
        ->and($tenant->is_default)->toBeTrue()
        ->and($tenant->is_active)->toBeTrue()
        ->and($connection->oracle_tenant_id)->toBe($tenant->id)
        ->and($connection->user_id)->toBe($user->id)
        ->and($connection->identifier)->toBe('svc_onboarding')
        ->and($connection->secret)->toBe('first-secret')
        ->and($connection->getRawOriginal('secret'))->not->toBe('first-secret')
        ->and($connection->is_default)->toBeTrue()
        ->and($connection->is_active)->toBeTrue()
        ->and($connection->verified_at)->not->toBeNull()
        ->and($connection->last_test_succeeded_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://production-ca.fa.oraclecloud.com/'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('svc_onboarding:first-secret')));
});

test('failed credential verification keeps onboarding incomplete and stores nothing', function () {
    Http::fake(['*' => Http::response([], 401)]);

    $user = User::factory()->withoutOnboarding()->create();

    $this->actingAs($user)
        ->from(route('onboarding.connection'))
        ->post(route('onboarding.connection.store'), [
            'key' => 'invalid',
            'label' => 'Invalid',
            'base_url' => 'https://invalid.fa.oraclecloud.com',
            'username' => 'svc_invalid',
            'password' => 'wrong',
        ])
        ->assertRedirect(route('onboarding.connection'))
        ->assertInvalid('connection');

    expect($user->refresh()->hasCompletedOnboarding())->toBeFalse()
        ->and($user->oracleTenants()->exists())->toBeFalse()
        ->and($user->authConnections()->exists())->toBeFalse();
});

test('posting onboarding again does not create a second tenant', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = createConnectedUser([], ['key' => 'existing']);

    $this->actingAs($user)
        ->post(route('onboarding.connection.store'), [
            'key' => 'duplicate_attempt',
            'label' => 'Duplicate attempt',
            'base_url' => 'https://duplicate.fa.oraclecloud.com',
            'username' => 'svc',
            'password' => 'secret',
        ])
        ->assertRedirect(route('dashboard'));

    expect($user->oracleTenants()->count())->toBe(1);
    Http::assertNothingSent();
});
