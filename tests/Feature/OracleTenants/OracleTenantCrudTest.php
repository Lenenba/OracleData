<?php

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

test('testConnection returns ok:true when Oracle is reachable', function () {
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

test('testConnection returns ok:false when Oracle is not reachable', function () {
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

// ─── edit ────────────────────────────────────────────────────────────────────

test('guests cannot access the tenant edit page', function () {
    $tenant = OracleTenant::factory()->create();

    $this->get(route('oracle-tenants.edit', $tenant))
        ->assertRedirect(route('login'));
});

test('the edit page renders with the tenant data', function () {
    $tenant = OracleTenant::factory()->create(['label' => 'Acme Corp']);

    $this->withoutVite()->actingAs(User::factory()->create())
        ->get(route('oracle-tenants.edit', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oracle-tenants/edit')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.label', 'Acme Corp')
        );
});

// ─── update ──────────────────────────────────────────────────────────────────

test('guests cannot update a tenant', function () {
    $tenant = OracleTenant::factory()->create();

    $this->put(route('oracle-tenants.update', $tenant), ['label' => 'New'])->assertRedirect(route('login'));

    expect($tenant->refresh()->label)->not->toBe('New');
});

test('an authenticated user can update a tenant label and base_url', function () {
    $tenant = OracleTenant::factory()->create([
        'label' => 'Old Label',
        'base_url' => 'https://old.fa.oraclecloud.com',
    ]);

    $this->actingAs(User::factory()->create())
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => 'New Label',
            'base_url' => 'https://new.fa.oraclecloud.com/',
            'username' => $tenant->username,
            'is_default' => '0',
            'is_active' => '1',
        ])
        ->assertRedirect(route('oracle-tenants.index'));

    expect($tenant->refresh()->label)->toBe('New Label')
        ->and($tenant->base_url)->toBe('https://new.fa.oraclecloud.com');
});

test('password is not overwritten when left blank on update', function () {
    $tenant = OracleTenant::factory()->create(['password' => 'original']);
    $originalEncrypted = $tenant->getRawOriginal('password');

    $this->actingAs(User::factory()->create())
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => $tenant->label,
            'base_url' => $tenant->base_url,
            'username' => $tenant->username,
            'password' => '',
            'is_default' => '0',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->getRawOriginal('password'))->toBe($originalEncrypted);
});

test('password is overwritten when a new value is provided on update', function () {
    $tenant = OracleTenant::factory()->create(['password' => 'original']);
    $originalEncrypted = $tenant->getRawOriginal('password');

    $this->actingAs(User::factory()->create())
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => $tenant->label,
            'base_url' => $tenant->base_url,
            'username' => $tenant->username,
            'password' => 'new-password',
            'is_default' => '0',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->getRawOriginal('password'))->not->toBe($originalEncrypted)
        ->and($tenant->password)->toBe('new-password');
});

test('setting a tenant as default clears the previous default on update', function () {
    $old = OracleTenant::factory()->default()->create(['key' => 'old']);
    $new = OracleTenant::factory()->create(['key' => 'new_one']);

    $this->actingAs(User::factory()->create())
        ->put(route('oracle-tenants.update', $new), [
            'label' => $new->label,
            'base_url' => $new->base_url,
            'username' => $new->username,
            'is_default' => '1',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($old->refresh()->is_default)->toBeFalse()
        ->and($new->refresh()->is_default)->toBeTrue();
});

test('update rejects an invalid base_url', function () {
    $tenant = OracleTenant::factory()->create();

    $this->actingAs(User::factory()->create())
        ->put(route('oracle-tenants.update', $tenant), [
            'label' => $tenant->label,
            'base_url' => 'not-a-url',
            'username' => $tenant->username,
        ])
        ->assertInvalid('base_url');
});

// ─── destroy ─────────────────────────────────────────────────────────────────

test('guests cannot delete a tenant', function () {
    $tenant = OracleTenant::factory()->create();

    $this->delete(route('oracle-tenants.destroy', $tenant))
        ->assertRedirect(route('login'));

    expect(OracleTenant::find($tenant->id))->not->toBeNull();
});

test('an authenticated user can delete a database tenant', function () {
    $tenant = OracleTenant::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('oracle-tenants.destroy', $tenant))
        ->assertRedirect(route('oracle-tenants.index'));

    expect(OracleTenant::find($tenant->id))->toBeNull();
});
