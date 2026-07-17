<?php

use App\Models\OracleTenant;
use App\Models\User;

test('a regular user cannot manage Oracle tenants', function () {
    $user = User::factory()->create();
    $tenant = OracleTenant::factory()->create();

    $this->actingAs($user)->get(route('oracle-tenants.index'))->assertForbidden();
    $this->actingAs($user)->post(route('oracle-tenants.store'), [])->assertForbidden();
    $this->actingAs($user)->postJson(route('oracle-tenants.test'), [])->assertForbidden();
    $this->actingAs($user)->get(route('oracle-tenants.edit', $tenant))->assertForbidden();
    $this->actingAs($user)->put(route('oracle-tenants.update', $tenant), [])->assertForbidden();
    $this->actingAs($user)->delete(route('oracle-tenants.destroy', $tenant))->assertForbidden();
});

test('a super administrator can open Oracle tenant management', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('oracle-tenants.index'))
        ->assertOk();
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
