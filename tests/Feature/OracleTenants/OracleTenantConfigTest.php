<?php

use App\Models\OracleTenant;
use App\Models\User;
use App\Services\FusionManager;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage Oracle tenants', function () {
    $this->get(route('oracle-tenants.index'))->assertRedirect(route('login'));
    $this->post(route('oracle-tenants.store'), [])->assertRedirect(route('login'));
});

test('the tenant configuration page renders configured tenants', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('oracle-tenants.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oracle-tenants/index')
            ->has('tenants')
            ->has('defaultTenant'));
});

test('an authenticated user can store an Oracle tenant with encrypted credentials', function () {
    $user = User::factory()->create();

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

    $tenant = OracleTenant::sole();

    expect($tenant->key)->toBe('client_z')
        ->and($tenant->base_url)->toBe('https://client-z.fa.oraclecloud.com')
        ->and($tenant->password)->toBe('super-secret')
        ->and($tenant->getRawOriginal('password'))->not->toBe('super-secret')
        ->and($tenant->is_default)->toBeTrue()
        ->and(app(FusionManager::class)->defaultKey())->toBe('client_z');
});

test('setting a new default tenant clears the previous database default', function () {
    $old = OracleTenant::factory()->default()->create(['key' => 'old_client']);

    $this->actingAs(User::factory()->create())
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
        ->and(OracleTenant::where('key', 'new_client')->sole()->is_default)->toBeTrue();
});
