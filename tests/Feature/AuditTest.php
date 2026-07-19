<?php

use App\Models\AuditEvent;
use App\Models\OracleTenant;
use App\Models\Query;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\Http;

// ─── AuditRecorder ───────────────────────────────────────────────────────────

test('record() persists actor, subject, context and action', function () {
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user);

    $event = app(AuditRecorder::class)->record($user, 'tenant.created', $tenant, [
        'tenant_key' => $tenant->key,
    ]);

    expect($event->user_id)->toBe($user->id)
        ->and($event->action)->toBe('tenant.created')
        ->and($event->subject_type)->toBe($tenant->getMorphClass())
        ->and($event->subject_id)->toBe($tenant->id)
        ->and($event->context)->toBe(['tenant_key' => $tenant->key]);
});

test('record() rejects sensitive context keys', function (string $key) {
    app(AuditRecorder::class)->record(null, 'tenant.updated', null, [$key => 'leak']);
})->throws(InvalidArgumentException::class)->with([
    'password',
    'secret',
    'identifier',
    'username',
    'token',
]);

test('record() rejects sensitive keys nested in the context', function () {
    app(AuditRecorder::class)->record(null, 'tenant.updated', null, [
        'changes' => ['password' => 'leak'],
    ]);
})->throws(InvalidArgumentException::class);

test('record() rejects compound sensitive context keys', function (string $key) {
    app(AuditRecorder::class)->record(null, 'tenant.updated', null, [
        $key => 'leak',
    ]);
})->throws(InvalidArgumentException::class)->with([
    'database_password',
    'oracle.password',
    'oracle-username',
    'request_authorization_header',
    'client_secret',
    'access_token',
    'refresh_token',
    'api_key',
    'private_key',
    'clientSecret',
    'accessToken',
    'apiKey',
    'privateKey',
    'oracle_api_key_value',
    'signing.private-key.version',
]);

test('audit events are immutable', function () {
    $event = AuditEvent::factory()->create();

    $event->update(['action' => 'tampered']);
})->throws(LogicException::class);

test('audit events cannot be deleted through Eloquent', function () {
    $event = AuditEvent::factory()->create();

    $event->delete();
})->throws(LogicException::class);

// ─── Mutations de tenants ────────────────────────────────────────────────────

test('creating a tenant records tenant.created without credentials', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $user = createConnectedUser();

    $this->actingAs($user)->post(route('oracle-tenants.store'), [
        'key' => 'client_new',
        'label' => 'Client New',
        'base_url' => 'https://client-new.fa.oraclecloud.com',
        'username' => 'svc_new',
        'password' => 'super-secret',
    ])->assertRedirect(route('oracle-tenants.index'));

    $tenant = OracleTenant::query()->where('key', 'client_new')->sole();
    $event = AuditEvent::query()->where('action', 'tenant.created')->sole();
    $stored = (string) json_encode($event->context);

    expect($event->user_id)->toBe($user->id)
        ->and($event->subject_id)->toBe($tenant->id)
        ->and($stored)->not->toContain('super-secret')
        ->and($stored)->not->toContain('svc_new');
});

test('updating a tenant records tenant.updated', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $user = createConnectedUser();
    $tenant = $user->oracleTenants()->sole();

    $this->actingAs($user)->put(route('oracle-tenants.update', $tenant), [
        'label' => 'Renommé',
        'base_url' => $tenant->base_url,
        'username' => 'svc_rotated',
        'password' => 'rotated-secret',
        'is_default' => true,
        'is_active' => true,
    ])->assertRedirect(route('oracle-tenants.index'));

    $event = AuditEvent::query()->where('action', 'tenant.updated')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->subject_id)->toBe($tenant->id)
        ->and($event->context)->toMatchArray(['credentials_changed' => true]);
});

test('deleting a tenant records tenant.deleted', function () {
    $user = createConnectedUser();
    $doomed = createOracleTenantFor($user);

    $this->actingAs($user)
        ->delete(route('oracle-tenants.destroy', $doomed))
        ->assertRedirect(route('oracle-tenants.index'));

    $event = AuditEvent::query()->where('action', 'tenant.deleted')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->subject_id)->toBe($doomed->id)
        ->and($event->context)->toMatchArray(['tenant_key' => $doomed->key]);
});

test('a rejected connection test records no audit event', function () {
    Http::fake(['*' => Http::response([], 401)]);
    $user = createConnectedUser();

    $this->actingAs($user)
        ->from(route('oracle-tenants.index'))
        ->post(route('oracle-tenants.store'), [
            'key' => 'client_broken',
            'label' => 'Client Broken',
            'base_url' => 'https://client-broken.fa.oraclecloud.com',
            'username' => 'svc_broken',
            'password' => 'wrong',
        ])
        ->assertRedirect(route('oracle-tenants.index'));

    expect(AuditEvent::query()->count())->toBe(0);
});

// ─── Onboarding ──────────────────────────────────────────────────────────────

test('completing onboarding records tenant.created and onboarding.completed', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $user = User::factory()->withoutOnboarding()->create();

    $this->actingAs($user)->post(route('onboarding.connection.store'), [
        'key' => 'production_ca',
        'label' => 'Production CA',
        'base_url' => 'https://production-ca.fa.oraclecloud.com',
        'username' => 'svc_onboarding',
        'password' => 'first-secret',
    ]);

    expect(AuditEvent::query()->where('action', 'tenant.created')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'onboarding.completed')->where('user_id', $user->id)->exists())->toBeTrue();
});

// ─── Exécutions ──────────────────────────────────────────────────────────────

test('running a query records query.executed with the resolved tenant', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0, 'hasMore' => false])]);
    $user = User::factory()->create();
    $tenant = createOracleTenantFor($user, [
        'key' => 'client_x',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ]);
    $query = Query::factory()->for($user)->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'oracle_tenant_id' => $tenant->id,
        'parameters' => ['limit' => 25],
    ]);

    $this->actingAs($user)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();

    $event = AuditEvent::query()->where('action', 'query.executed')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->subject_id)->toBe($query->id)
        ->and($event->context)->toMatchArray([
            'tenant_key' => 'client_x',
            'mode' => $query->mode,
        ]);
});

test('previews do not record audit events', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0, 'hasMore' => false])]);
    $user = createConnectedUser();

    $this->actingAs($user)->postJson(route('queries.direct-preview'), [
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant' => $user->oracleTenants()->sole()->key,
    ]);

    expect(AuditEvent::query()->count())->toBe(0);
});

// ─── Administration ──────────────────────────────────────────────────────────

test('granting super admin records an audit event without actor', function () {
    $user = User::factory()->create();

    $this->artisan('user:grant-super-admin', ['email' => $user->email])
        ->assertSuccessful();

    $event = AuditEvent::query()->where('action', 'admin.super_admin_granted')->sole();

    expect($event->user_id)->toBeNull()
        ->and($event->subject_id)->toBe($user->id);
});
