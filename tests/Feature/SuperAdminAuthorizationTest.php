<?php

use App\Models\User;

test('tenant management is available to every connected user', function () {
    $user = createConnectedUser();

    $this->actingAs($user)
        ->get(route('oracle-tenants.index'))
        ->assertOk();

    $this->actingAs($user)
        ->post(route('oracle-tenants.store'), [])
        ->assertInvalid(['key', 'label', 'base_url', 'username', 'password']);
});

test('super administrator status does not bypass tenant ownership', function () {
    $owner = createConnectedUser();
    $superAdmin = createConnectedUser(['is_super_admin' => true]);
    $tenant = $owner->oracleTenants()->firstOrFail();

    $this->actingAs($superAdmin)
        ->get(route('oracle-tenants.edit', $tenant))
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->put(route('oracle-tenants.update', $tenant), [])
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->delete(route('oracle-tenants.destroy', $tenant))
        ->assertForbidden();
});

test('the connection test endpoint is available to every authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('oracle-tenants.test'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['base_url', 'username', 'password']);
});

test('the console command grants super administrator access to an existing user', function () {
    $user = User::factory()->create();

    $this->artisan('user:grant-super-admin', ['email' => $user->email])
        ->assertSuccessful();

    expect($user->refresh()->isSuperAdmin())->toBeTrue();
});

test('the console command fails for an unknown user', function () {
    $this->artisan('user:grant-super-admin', ['email' => 'missing@example.com'])
        ->assertFailed();
});
