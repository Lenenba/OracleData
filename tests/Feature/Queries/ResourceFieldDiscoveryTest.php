<?php

use App\Models\OracleResourceField;
use App\Services\OracleFieldDiscovery;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->runner = createConnectedUser([], [
        'key' => 'client_x',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ]);
});

test('the probe returns live fields merged with the catalog, links excluded', function () {
    Http::fake(['*' => Http::response([
        'items' => [[
            'SupplierId' => 1,
            'Supplier' => 'Acme',
            'BrandNewField' => null,
            'links' => [['rel' => 'self']],
        ]],
    ])]);

    $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
        ])
        ->assertOk()
        ->assertJsonPath('source', 'live')
        ->assertJsonMissing(['fields' => ['links']]);

    $fields = $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->json('fields');

    expect($fields)->toContain('BrandNewField')
        ->toContain('SupplierNumber')
        ->not->toContain('links')
        ->and($fields[0])->toBe('SupplierId');
});

test('a child probe reads the first non-empty expanded child', function () {
    Http::fake(['*' => Http::response([
        'items' => [
            ['SupplierId' => 1, 'sites' => ['items' => []]],
            ['SupplierId' => 2, 'sites' => ['items' => [[
                'SiteId' => 77,
                'SupplierSite' => 'Main',
                'DiscoveredChildField' => 'x',
                'links' => [],
            ]]]],
        ],
    ])]);

    $response = $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
            'child' => 'sites',
        ])
        ->assertOk()
        ->assertJsonPath('source', 'live');

    expect($response->json('fields'))->toContain('DiscoveredChildField')
        ->toContain('City')
        ->not->toContain('links');
});

test('an unknown resource key is rejected without any Oracle call', function () {
    Http::fake();

    $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'not-in-catalog',
        ])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

test('an unknown child is rejected', function () {
    Http::fake();

    $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
            'child' => 'not-a-child',
        ])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

test('a tenant the reader does not own is rejected', function () {
    Http::fake();
    createConnectedUser([], ['key' => 'foreign_env']);

    $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'foreign_env',
            'resource_key' => 'suppliers',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tenant']);

    Http::assertNothingSent();
});

test('an Oracle failure falls back to the catalog fields', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $response = $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
        ])
        ->assertOk()
        ->assertJsonPath('source', 'catalog');

    expect($response->json('fields'))->toContain('SupplierNumber');
});

test('an empty resource falls back to the catalog fields without caching', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
        ])
        ->assertOk()
        ->assertJsonPath('source', 'catalog');
});

test('the discovery is cached per tenant and resource', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'links' => []]],
    ])]);

    $this->actingAs($this->runner);
    $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->assertOk();
    $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->assertOk();

    Http::assertSentCount(1);
});

test('guests cannot discover fields', function () {
    $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->assertUnauthorized();
});

// ─── Cache persistant en base ────────────────────────────────────────────────

test('a successful probe is persisted to the oracle_resource_fields table', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'InactiveDate' => null, 'links' => []]],
    ])]);

    $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
        ])
        ->assertOk()
        ->assertJsonPath('source', 'live');

    $tenant = $this->runner->oracleTenants()->sole();
    $row = OracleResourceField::query()->sole();

    expect($row->oracle_tenant_id)->toBe($tenant->id)
        ->and($row->resource_key)->toBe('suppliers')
        ->and($row->child)->toBe('')
        ->and($row->fields)->toContain('InactiveDate');
});

test('a fresh persisted row is served without probing Oracle again', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'links' => []]],
    ])]);

    $this->actingAs($this->runner);
    $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->assertOk();

    // Deuxième requête sur une nouvelle instance de service (nouvelle « requête »)
    $this->app->forgetInstance(OracleFieldDiscovery::class);

    $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->assertOk()->assertJsonPath('source', 'live');

    Http::assertSentCount(1);
});

test('a stale persisted row is re-probed and refreshed', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'NewlyAddedField' => null, 'links' => []]],
    ])]);

    $tenant = $this->runner->oracleTenants()->sole();
    OracleResourceField::query()->create([
        'oracle_tenant_id' => $tenant->id,
        'resource_key' => 'suppliers',
        'child' => '',
        'fields' => ['OldFieldOnly'],
        'discovered_at' => now()->subDays(30),
    ]);

    $response = $this->actingAs($this->runner)
        ->postJson(route('queries.resource-fields'), [
            'tenant' => 'client_x',
            'resource_key' => 'suppliers',
        ])
        ->assertOk()
        ->assertJsonPath('source', 'live');

    expect($response->json('fields'))->toContain('NewlyAddedField')
        ->and(OracleResourceField::query()->sole()->fields)->toContain('NewlyAddedField');

    Http::assertSentCount(1);
});

test('the cache is scoped per tenant', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/*' => Http::response([
            'items' => [['SupplierId' => 1, 'FieldForX' => null, 'links' => []]],
        ]),
        'https://client-y.fa.oraclecloud.com/*' => Http::response([
            'items' => [['SupplierId' => 2, 'FieldForY' => null, 'links' => []]],
        ]),
    ]);

    createOracleTenantFor($this->runner, [
        'key' => 'client_y',
        'base_url' => 'https://client-y.fa.oraclecloud.com',
    ]);

    $this->actingAs($this->runner);
    $x = $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_x',
        'resource_key' => 'suppliers',
    ])->json('fields');
    $y = $this->postJson(route('queries.resource-fields'), [
        'tenant' => 'client_y',
        'resource_key' => 'suppliers',
    ])->json('fields');

    expect($x)->toContain('FieldForX')->not->toContain('FieldForY')
        ->and($y)->toContain('FieldForY')->not->toContain('FieldForX')
        ->and(OracleResourceField::query()->count())->toBe(2);
});

test('the flush command clears cached fields for re-sync', function () {
    $tenant = $this->runner->oracleTenants()->sole();
    OracleResourceField::query()->create([
        'oracle_tenant_id' => $tenant->id,
        'resource_key' => 'suppliers',
        'child' => '',
        'fields' => ['SupplierId'],
        'discovered_at' => now(),
    ]);

    $this->artisan('oracle:flush-fields')->assertSuccessful();

    expect(OracleResourceField::query()->count())->toBe(0);
});

test('the flush command can target a single tenant', function () {
    $tenantX = $this->runner->oracleTenants()->sole();
    $other = createConnectedUser();
    $tenantOther = $other->oracleTenants()->sole();

    foreach ([$tenantX->id, $tenantOther->id] as $id) {
        OracleResourceField::query()->create([
            'oracle_tenant_id' => $id,
            'resource_key' => 'suppliers',
            'child' => '',
            'fields' => ['SupplierId'],
            'discovered_at' => now(),
        ]);
    }

    $this->artisan('oracle:flush-fields', ['--tenant' => $tenantX->key])->assertSuccessful();

    expect(OracleResourceField::query()->count())->toBe(1)
        ->and(OracleResourceField::query()->sole()->oracle_tenant_id)->toBe($tenantOther->id);
});

// ─── Commande de préchauffage ────────────────────────────────────────────────

test('the warm command pre-populates the fields table for a tenant', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'Supplier' => 'Acme', 'links' => []]],
    ])]);

    $this->artisan('oracle:warm-fields', ['--tenant' => 'client_x'])
        ->assertSuccessful();

    $tenant = $this->runner->oracleTenants()->sole();

    // Au moins la racine de chaque ressource du catalogue est désormais en base.
    expect(OracleResourceField::query()->where('oracle_tenant_id', $tenant->id)->where('child', '')->count())
        ->toBeGreaterThan(1)
        ->and(OracleResourceField::query()
            ->where('oracle_tenant_id', $tenant->id)
            ->where('resource_key', 'suppliers')
            ->where('child', '')
            ->exists())
        ->toBeTrue();
});

test('the warm command fails when no tenant matches', function () {
    $this->artisan('oracle:warm-fields', ['--tenant' => 'does-not-exist'])
        ->assertFailed();
});
