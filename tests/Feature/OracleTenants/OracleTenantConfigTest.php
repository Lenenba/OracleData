<?php

use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\User;
use App\Services\FusionManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage Oracle tenants', function () {
    $this->get(route('oracle-tenants.index'))->assertRedirect(route('login'));
    $this->post(route('oracle-tenants.store'), [])->assertRedirect(route('login'));
});

test('the tenant configuration page renders only the authenticated users tenants', function () {
    $user = User::factory()->create();
    $mine = createOracleTenantFor($user, ['key' => 'mine', 'label' => 'Mine']);
    createOracleTenantFor(User::factory()->create(), ['key' => 'theirs', 'label' => 'Theirs']);

    $this->actingAs($user)
        ->get(route('oracle-tenants.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oracle-tenants/index')
            ->has('tenants', 1)
            ->where('tenants.0.id', $mine->id)
            ->where('tenants.0.key', 'mine')
            ->where('defaultTenant', 'mine')
        );
});

test('an authenticated user can store an owned tenant with encrypted credentials', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = createConnectedUser([], ['key' => 'existing']);

    $this->actingAs($user)
        ->post(route('oracle-tenants.store'), [
            'key' => 'client_z',
            'label' => 'Client Z',
            'base_url' => 'https://client-z.fa.oraclecloud.com/',
            'username' => 'svc_z',
            'password' => 'super-secret',
            'is_default' => '1',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('oracle-tenants.index'));

    $tenant = OracleTenant::whereBelongsTo($user)->where('key', 'client_z')->sole();
    $connection = $tenant->authConnections()->sole();

    expect($tenant->user_id)->toBe($user->id)
        ->and($tenant->base_url)->toBe('https://client-z.fa.oraclecloud.com')
        ->and($tenant->is_default)->toBeTrue()
        ->and($connection->user_id)->toBe($user->id)
        ->and($connection->identifier)->toBe('svc_z')
        ->and($connection->secret)->toBe('super-secret')
        ->and($connection->getRawOriginal('secret'))->not->toBe('super-secret')
        ->and($connection->verified_at)->not->toBeNull()
        ->and(app(FusionManager::class)->forUser($user)->defaultKey())->toBe('client_z');
});

test('setting a new default clears only the same users previous default', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $other = User::factory()->create();
    $old = createOracleTenantFor($user, ['key' => 'old_client', 'is_default' => true]);
    $otherDefault = createOracleTenantFor($other, ['key' => 'old_client', 'is_default' => true]);

    $this->actingAs($user)
        ->post(route('oracle-tenants.store'), [
            'key' => 'new_client',
            'label' => 'New Client',
            'base_url' => 'https://new-client.fa.oraclecloud.com',
            'username' => 'svc_new',
            'password' => 'secret',
            'is_default' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($old->refresh()->is_default)->toBeFalse()
        ->and($user->oracleTenants()->where('key', 'new_client')->sole()->is_default)->toBeTrue()
        ->and($otherDefault->refresh()->is_default)->toBeTrue();
});

test('different users can reuse the same tenant key', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $first = createConnectedUser([], ['key' => 'existing_first']);
    $second = createConnectedUser([], ['key' => 'existing_second']);
    $payload = [
        'key' => 'production',
        'label' => 'Production',
        'base_url' => 'https://production.fa.oraclecloud.com',
        'username' => 'svc',
        'password' => 'secret',
    ];

    $this->actingAs($first)
        ->post(route('oracle-tenants.store'), $payload)
        ->assertSessionHasNoErrors();

    $this->actingAs($second)
        ->post(route('oracle-tenants.store'), $payload)
        ->assertSessionHasNoErrors();

    expect(OracleTenant::where('key', 'production')->count())->toBe(2)
        ->and(AuthConnection::where('identifier', 'svc')->count())->toBe(2);
});

test('the database rejects an authentication connection owned by another tenant user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $tenant = createOracleTenantFor($owner);

    AuthConnection::factory()->create([
        'user_id' => $other->id,
        'oracle_tenant_id' => $tenant->id,
    ]);
})->throws(QueryException::class);
