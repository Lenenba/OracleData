<?php

use App\Models\AuditEvent;
use App\Models\OracleResourceField;
use App\Models\OracleResourceSchemaSnapshot;
use App\Services\OracleFieldDiscovery;
use App\Services\OracleSchemaSynchronizationService;
use Illuminate\Support\Facades\Http;

function oracleDescribePayload(bool $withNewField = false): array
{
    $attributes = [
        ['name' => 'SupplierId', 'type' => 'integer', 'updatable' => false],
        ['name' => 'Supplier', 'type' => 'string', 'maxLength' => 240],
    ];

    if ($withNewField) {
        $attributes[] = ['name' => 'RiskLevel', 'type' => 'string'];
    }

    return [
        'Resources' => [
            'suppliers' => [
                'title' => 'Suppliers',
                'attributes' => $attributes,
            ],
        ],
    ];
}

beforeEach(function () {
    $this->owner = createConnectedUser([], [
        'key' => 'client_x',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
    ]);
    $this->tenant = $this->owner->oracleTenants()->sole();
});

test('first identical and changed describe synchronizations keep one current schema and immutable history', function () {
    Http::fakeSequence()
        ->push(oracleDescribePayload())
        ->push(oracleDescribePayload())
        ->push(oracleDescribePayload(withNewField: true));

    $this->actingAs($this->owner)
        ->postJson(route('oracle-schema.sync', $this->tenant), ['resource_key' => 'suppliers'])
        ->assertOk()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('resource.source', 'describe');

    $current = OracleResourceField::query()->sole();

    expect($current->fields)->toBe(['Supplier', 'SupplierId'])
        ->and(OracleResourceSchemaSnapshot::query()->count())->toBe(1);

    $this->postJson(route('oracle-schema.sync', $this->tenant), ['resource_key' => 'suppliers'])
        ->assertOk()
        ->assertJsonPath('status', 'unchanged');

    expect(OracleResourceSchemaSnapshot::query()->count())->toBe(1);

    $this->postJson(route('oracle-schema.sync', $this->tenant), ['resource_key' => 'suppliers'])
        ->assertOk()
        ->assertJsonPath('status', 'changed');

    expect(OracleResourceField::query()->sole()->fields)->toContain('RiskLevel')
        ->and(OracleResourceSchemaSnapshot::query()->count())->toBe(2)
        ->and(OracleResourceSchemaSnapshot::query()->distinct()->count('schema_hash'))->toBe(2);

    $auditContexts = AuditEvent::query()
        ->where('action', 'oracle.schema_synchronized')
        ->pluck('context')
        ->all();
    $encodedAudit = json_encode($auditContexts, JSON_THROW_ON_ERROR);

    expect($auditContexts)->toHaveCount(3)
        ->and($encodedAudit)->not->toContain('Resources')
        ->not->toContain('attributes')
        ->not->toContain('oraclecloud.com')
        ->not->toContain('secret');
});

test('a malformed describe response leaves the current schema snapshot and audit unchanged', function () {
    Http::fakeSequence()
        ->push(oracleDescribePayload())
        ->push(['Resources' => ['suppliers' => ['attributes' => []]]]);
    $service = app(OracleSchemaSynchronizationService::class);
    $service->synchronize($this->owner, $this->tenant, 'suppliers');
    $originalHash = OracleResourceField::query()->sole()->schema_hash;

    expect(fn () => $service->synchronize($this->owner, $this->tenant, 'suppliers'))
        ->toThrow(UnexpectedValueException::class);

    expect(OracleResourceField::query()->sole()->schema_hash)->toBe($originalHash)
        ->and(OracleResourceSchemaSnapshot::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'oracle.schema_synchronized')->count())->toBe(1);
});

test('schema routes reject unknown resources and tenants owned by another user before Oracle is called', function () {
    Http::fake();

    $this->actingAs($this->owner)
        ->postJson(route('oracle-schema.sync', $this->tenant), ['resource_key' => 'not-in-catalog'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['resource_key']);

    $other = createConnectedUser([], ['key' => 'foreign']);
    $foreignTenant = $other->oracleTenants()->sole();

    $this->getJson(route('oracle-schema.index', $foreignTenant))->assertForbidden();
    $this->postJson(route('oracle-schema.sync', $foreignTenant), ['resource_key' => 'suppliers'])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('schema index exposes only safe synchronized metadata to its owner', function () {
    OracleResourceField::query()->create([
        'oracle_tenant_id' => $this->tenant->id,
        'resource_key' => 'suppliers',
        'child' => '',
        'fields' => ['SupplierId'],
        'source' => 'describe',
        'title' => 'Suppliers',
        'attributes' => [['name' => 'SupplierId', 'type' => 'integer']],
        'schema_hash' => str_repeat('a', 64),
        'discovered_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->getJson(route('oracle-schema.index', $this->tenant))
        ->assertOk()
        ->assertJsonPath('tenant.id', $this->tenant->id)
        ->assertJsonPath('resources.0.resource_key', 'suppliers')
        ->assertJsonMissingPath('tenant.base_url');
});

test('field discovery always prefers describe metadata and never probes over it', function () {
    OracleResourceField::query()->create([
        'oracle_tenant_id' => $this->tenant->id,
        'resource_key' => 'suppliers',
        'child' => '',
        'fields' => ['DescribeOnlyField'],
        'source' => 'describe',
        'title' => 'Suppliers',
        'attributes' => [['name' => 'DescribeOnlyField', 'type' => 'string']],
        'schema_hash' => str_repeat('b', 64),
        'discovered_at' => now()->subYear(),
    ]);
    Http::fake();

    $fields = app(OracleFieldDiscovery::class)->discovered(
        $this->owner->id,
        $this->tenant->key,
        'suppliers',
    );

    expect($fields)->toBe(['DescribeOnlyField'])
        ->and(OracleResourceField::query()->sole()->source)->toBe('describe');
    Http::assertNothingSent();
});

test('schema command can target one tenant and one catalog resource', function () {
    Http::fake(['*' => Http::response(oracleDescribePayload())]);

    $this->artisan('oracle:sync-schema', [
        '--tenant' => 'client_x',
        '--user' => $this->owner->email,
        '--resource' => 'suppliers',
    ])->assertSuccessful();

    expect(OracleResourceField::query()->count())->toBe(1)
        ->and(OracleResourceField::query()->sole()->resource_key)->toBe('suppliers');
    Http::assertSentCount(1);
});
