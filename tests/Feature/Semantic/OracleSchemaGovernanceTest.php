<?php

use App\Enums\OracleSchemaImpactStatus;
use App\Models\OracleResourceField;
use App\Models\OracleResourceSchemaSnapshot;
use App\Models\OracleSchemaImpact;
use App\Models\SemanticCatalogVersion;
use App\Models\SemanticField;
use App\Services\OracleSchemaSynchronizationService;
use App\Services\SemanticCatalogSynchronizer;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    app(SemanticCatalogSynchronizer::class)->synchronize();
});

test('the observed schema page exposes only the owners tenant and its drift props', function () {
    $owner = createConnectedUser();
    $outsider = createConnectedUser();
    $tenant = $owner->oracleTenants()->sole();
    $previous = OracleResourceSchemaSnapshot::factory()->for($tenant, 'oracleTenant')->create([
        'fields' => ['SupplierId', 'Supplier'],
        'diff' => ['added' => ['SupplierId', 'Supplier'], 'removed' => [], 'changed' => []],
    ]);
    $latest = OracleResourceSchemaSnapshot::factory()->for($tenant, 'oracleTenant')->create([
        'previous_snapshot_id' => $previous->id,
        'fields' => ['SupplierId'],
        'diff' => ['added' => [], 'removed' => ['Supplier'], 'changed' => []],
        'synced_at' => now()->addSecond(),
    ]);
    OracleResourceField::query()->create([
        'oracle_tenant_id' => $tenant->id,
        'resource_key' => 'suppliers',
        'child' => '',
        'fields' => ['SupplierId'],
        'source' => 'describe',
        'title' => 'Suppliers',
        'attributes' => [['name' => 'SupplierId', 'type' => 'integer']],
        'schema_hash' => $latest->schema_hash,
        'discovered_at' => now(),
    ]);
    OracleSchemaImpact::factory()->for($tenant, 'oracleTenant')->create([
        'oracle_resource_schema_snapshot_id' => $latest->id,
        'resource_key' => 'suppliers',
    ]);

    $this->withoutVite()
        ->actingAs($owner)
        ->get(route('oracle-schema.page', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oracle-tenants/schema')
            ->where('tenant.id', $tenant->id)
            ->where('capabilities.synchronize', true)
            ->where('capabilities.acknowledge_impacts', true)
            ->where('resources', function ($resources): bool {
                $suppliers = collect($resources)->firstWhere('resource_key', 'suppliers');

                return is_array($suppliers)
                    && $suppliers['drift_status'] === 'changed'
                    && $suppliers['drift']['removed'] === ['Supplier']
                    && count($suppliers['history']) === 2;
            }));

    $this->actingAs($outsider)
        ->get(route('oracle-schema.page', $tenant))
        ->assertForbidden();
});

test('impact acknowledgement is tenant isolated and records the owner', function () {
    $owner = createConnectedUser();
    $otherOwner = createConnectedUser();
    $tenant = $owner->oracleTenants()->sole();
    $otherTenant = $otherOwner->oracleTenants()->sole();
    $snapshot = OracleResourceSchemaSnapshot::factory()->for($tenant, 'oracleTenant')->create();
    $otherSnapshot = OracleResourceSchemaSnapshot::factory()->for($otherTenant, 'oracleTenant')->create();
    $impact = OracleSchemaImpact::factory()->for($tenant, 'oracleTenant')->create([
        'oracle_resource_schema_snapshot_id' => $snapshot->id,
        'resource_key' => 'suppliers',
    ]);
    $otherImpact = OracleSchemaImpact::factory()->for($otherTenant, 'oracleTenant')->create([
        'oracle_resource_schema_snapshot_id' => $otherSnapshot->id,
        'resource_key' => 'suppliers',
    ]);

    $this->actingAs($otherOwner)
        ->post(route('oracle-schema.impacts.acknowledge', [$tenant, 'suppliers']))
        ->assertForbidden();

    expect($impact->fresh()->status)->toBe(OracleSchemaImpactStatus::Open);

    $this->actingAs($owner)
        ->post(route('oracle-schema.impacts.acknowledge', [$tenant, 'suppliers']))
        ->assertRedirect();

    $impact->refresh();

    expect($impact->status)->toBe(OracleSchemaImpactStatus::Acknowledged)
        ->and($impact->acknowledged_by_user_id)->toBe($owner->id)
        ->and($impact->acknowledged_at)->not->toBeNull()
        ->and($otherImpact->fresh()->status)->toBe(OracleSchemaImpactStatus::Open);
});

test('describe enriches governed technical facts without expanding the semantic allow-list', function () {
    $owner = createConnectedUser();
    $tenant = $owner->oracleTenants()->sole();
    $payload = [
        'Resources' => [
            'suppliers' => [
                'title' => 'Suppliers',
                'attributes' => [
                    [
                        'name' => 'SupplierId',
                        'type' => 'integer',
                        'nullable' => false,
                        'updatable' => false,
                    ],
                    [
                        'name' => 'UnknownDescribeField',
                        'type' => 'string',
                        'nullable' => true,
                        'updatable' => true,
                    ],
                ],
            ],
        ],
    ];
    Http::fakeSequence()->push($payload)->push($payload);
    $service = app(OracleSchemaSynchronizationService::class);
    $initialVersionCount = SemanticCatalogVersion::query()->count();
    $initialFieldCount = SemanticField::query()->count();

    $first = $service->synchronize($owner, $tenant, 'suppliers');
    $supplierId = SemanticField::query()
        ->whereHas('semanticResource', fn ($query) => $query->where('resource_key', 'suppliers'))
        ->where('source_name', 'SupplierId')
        ->sole();

    expect($first['status'])->toBe('created')
        ->and($supplierId->data_type)->toBe('integer')
        ->and($supplierId->is_nullable)->toBeFalse()
        ->and($supplierId->is_updatable)->toBeFalse()
        ->and($supplierId->last_seen_at)->not->toBeNull()
        ->and(SemanticCatalogVersion::query()->count())->toBe($initialVersionCount + 1)
        ->and(SemanticField::query()->count())->toBe($initialFieldCount)
        ->and(SemanticField::query()->where('source_name', 'UnknownDescribeField')->exists())
        ->toBeFalse();

    $publishedVersionId = SemanticCatalogVersion::query()->whereNotNull('published_slot')->value('id');
    $second = $service->synchronize($owner, $tenant, 'suppliers');

    expect($second['status'])->toBe('unchanged')
        ->and(SemanticCatalogVersion::query()->count())->toBe($initialVersionCount + 1)
        ->and(SemanticCatalogVersion::query()->whereNotNull('published_slot')->value('id'))
        ->toBe($publishedVersionId)
        ->and(SemanticField::query()->count())->toBe($initialFieldCount);
});
