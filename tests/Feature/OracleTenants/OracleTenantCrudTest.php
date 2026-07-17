<?php

use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// ─── testConnection ──────────────────────────────────────────────────────────

test('guests cannot test a connection', function () {
    $this->post(route('oracle-tenants.test'), [
        'base_url' => 'https://x.fa.oraclecloud.com',
        'username' => 'svc',
        'password' => 'secret',
    ])->assertRedirect(route('login'));
});

test('every authenticated user can test a reachable Oracle connection', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('oracle-tenants.test'), [
            'base_url' => 'https://x.fa.oraclecloud.com',
            'username' => 'svc',
            'password' => 'secret',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('testConnection returns ok false when Oracle is not reachable', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('oracle-tenants.test'), [
            'base_url' => 'https://x.fa.oraclecloud.com',
            'username' => 'svc',
            'password' => 'secret',
        ])
        ->assertOk()
        ->assertJsonPath('ok', false);
});

test('testConnection validates base_url is a URL', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('oracle-tenants.test'), [
            'base_url' => 'not-a-url',
            'username' => 'svc',
            'password' => 'secret',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('base_url');
});

test('connection tests fail closed when no Oracle host allowlist is configured', function () {
    config()->set('fusion.allowed_host_suffixes', []);
    Http::fake(['*' => Http::response([], 200)]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('oracle-tenants.test'), [
            'base_url' => 'https://x.fa.oraclecloud.com',
            'username' => 'svc',
            'password' => 'secret',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('base_url');

    Http::assertNothingSent();
});

test('testConnection requires all three fields', function (string $field) {
    $payload = [
        'base_url' => 'https://x.fa.oraclecloud.com',
        'username' => 'svc',
        'password' => 'secret',
    ];
    unset($payload[$field]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('oracle-tenants.test'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['base_url', 'username', 'password']);

// ─── edit / ownership ────────────────────────────────────────────────────────

test('guests cannot access the tenant edit page', function () {
    $tenant = createOracleTenantFor(User::factory()->create());

    $this->get(route('oracle-tenants.edit', $tenant))
        ->assertRedirect(route('login'));
});

test('an owner can open the edit page with tenant and connection data', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor(
        $user,
        ['label' => 'Acme Corp'],
        ['identifier' => 'svc_acme'],
    );

    $this->withoutVite()
        ->actingAs($user)
        ->get(route('oracle-tenants.edit', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oracle-tenants/edit')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.label', 'Acme Corp')
            ->where('tenant.username', 'svc_acme')
        );
});

test('a user cannot read update or delete another users tenant', function () {
    $owner = createConnectedUser();
    $attacker = createConnectedUser();
    $tenant = $owner->oracleTenants()->firstOrFail();

    $this->actingAs($attacker)
        ->get(route('oracle-tenants.edit', $tenant))
        ->assertForbidden();

    $this->actingAs($attacker)
        ->put(route('oracle-tenants.update', $tenant), [])
        ->assertForbidden();

    $this->actingAs($attacker)
        ->delete(route('oracle-tenants.destroy', $tenant))
        ->assertForbidden();

    expect(OracleTenant::find($tenant->id))->not->toBeNull();
});

// ─── update ──────────────────────────────────────────────────────────────────

test('guests cannot update a tenant', function () {
    $tenant = createOracleTenantFor(User::factory()->create());

    $this->put(route('oracle-tenants.update', $tenant), ['label' => 'New'])
        ->assertRedirect(route('login'));

    expect($tenant->refresh()->label)->not->toBe('New');
});

test('an owner can update tenant and primary authentication data', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user, [
        'label' => 'Old Label',
        'base_url' => 'https://old.fa.oraclecloud.com',
    ], [
        'identifier' => 'old_user',
        'secret' => 'old-password',
    ]);

    $this->actingAs($user)
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => 'New Label',
            'base_url' => 'https://new.fa.oraclecloud.com/',
            'username' => 'new_user',
            'password' => 'new-password',
            'is_default' => '1',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('oracle-tenants.index'));

    $connection = $tenant->authConnections()->sole();

    expect($tenant->refresh()->label)->toBe('New Label')
        ->and($tenant->base_url)->toBe('https://new.fa.oraclecloud.com')
        ->and($connection->identifier)->toBe('new_user')
        ->and($connection->secret)->toBe('new-password')
        ->and($connection->verified_at)->not->toBeNull();
});

test('the encrypted secret is not overwritten when password is blank', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user, [], ['secret' => 'original']);
    $connection = $tenant->authConnections()->sole();
    $originalEncrypted = $connection->getRawOriginal('secret');

    $this->actingAs($user)
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => $tenant->label,
            'base_url' => $tenant->base_url,
            'username' => $connection->identifier,
            'password' => '',
            'is_default' => '1',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($connection->refresh()->getRawOriginal('secret'))->toBe($originalEncrypted)
        ->and($connection->secret)->toBe('original');
});

test('setting a tenant as default only clears the owners previous default', function () {
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $other = User::factory()->create();
    $old = createOracleTenantFor($user, ['key' => 'old', 'is_default' => true]);
    $new = createOracleTenantFor($user, ['key' => 'new_one', 'is_default' => false]);
    $otherDefault = createOracleTenantFor($other, ['key' => 'old', 'is_default' => true]);
    $connection = $new->authConnections()->sole();

    $this->actingAs($user)
        ->put(route('oracle-tenants.update', $new), [
            'label' => $new->label,
            'base_url' => $new->base_url,
            'username' => $connection->identifier,
            'is_default' => '1',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($old->refresh()->is_default)->toBeFalse()
        ->and($new->refresh()->is_default)->toBeTrue()
        ->and($otherDefault->refresh()->is_default)->toBeTrue();
});

test('update rejects an invalid base_url', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user);
    $connection = $tenant->authConnections()->sole();

    $this->actingAs($user)
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => $tenant->label,
            'base_url' => 'not-a-url',
            'username' => $connection->identifier,
        ])
        ->assertInvalid('base_url');
});

test('an owner cannot deactivate their last active connection', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user);
    $connection = $tenant->authConnections()->sole();

    $this->actingAs($user)
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => $tenant->label,
            'base_url' => $tenant->base_url,
            'username' => $connection->identifier,
            'is_active' => '0',
        ])
        ->assertInvalid('is_active');

    expect($tenant->refresh()->is_active)->toBeTrue()
        ->and($connection->refresh()->is_active)->toBeTrue();
});

// ─── destroy ─────────────────────────────────────────────────────────────────

test('guests cannot delete a tenant', function () {
    $tenant = createOracleTenantFor(User::factory()->create());

    $this->delete(route('oracle-tenants.destroy', $tenant))
        ->assertRedirect(route('login'));

    expect(OracleTenant::find($tenant->id))->not->toBeNull();
});

test('an owner can delete a tenant when another active connection remains', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user, ['key' => 'to_delete', 'is_default' => true]);
    $replacement = createOracleTenantFor($user, ['key' => 'replacement', 'is_default' => false]);
    $connectionIds = $tenant->authConnections()->pluck('id');

    $this->actingAs($user)
        ->delete(route('oracle-tenants.destroy', $tenant))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('oracle-tenants.index'));

    expect(OracleTenant::find($tenant->id))->toBeNull()
        ->and(AuthConnection::whereKey($connectionIds)->exists())->toBeFalse()
        ->and($replacement->refresh()->is_default)->toBeTrue();
});

test('deleting a default connection selects another verified connection', function () {
    $user = User::factory()->create();
    $current = createOracleTenantFor($user, ['key' => 'current', 'is_default' => true]);
    $unverified = createOracleTenantFor(
        $user,
        ['key' => 'unverified', 'is_default' => false],
        ['verified_at' => null],
    );
    $replacement = createOracleTenantFor($user, ['key' => 'replacement', 'is_default' => false]);

    $this->actingAs($user)
        ->delete(route('oracle-tenants.destroy', $current))
        ->assertSessionHasNoErrors();

    expect($unverified->refresh()->is_default)->toBeFalse()
        ->and($replacement->refresh()->is_default)->toBeTrue();
});

test('an unverified tenant can be deleted while one verified connection remains', function () {
    $user = User::factory()->create();
    createOracleTenantFor($user, ['key' => 'verified', 'is_default' => true]);
    $unverified = createOracleTenantFor(
        $user,
        ['key' => 'unverified', 'is_default' => false],
        ['verified_at' => null],
    );

    $this->actingAs($user)
        ->delete(route('oracle-tenants.destroy', $unverified))
        ->assertSessionHasNoErrors();

    expect(OracleTenant::find($unverified->id))->toBeNull();
});

test('an owner cannot delete their last active connection', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user);

    $this->actingAs($user)
        ->delete(route('oracle-tenants.destroy', $tenant))
        ->assertInvalid('connection');

    expect(OracleTenant::find($tenant->id))->not->toBeNull();
});
