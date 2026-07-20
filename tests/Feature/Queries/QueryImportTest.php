<?php

use App\Enums\QueryAccessLevel;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\User;
use App\Services\SemanticLineageService;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

beforeEach(function () {
    $this->owner = User::factory()->create();
    createOracleTenantFor($this->owner, [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ], ['identifier' => 'svc_x', 'secret' => 'secret_x']);
});

function workersCollection(): array
{
    return [
        'info' => ['name' => 'Workers'],
        'item' => [
            [
                'name' => 'Get Copy',
                'request' => [
                    'method' => 'GET',
                    'url' => [
                        'raw' => '{{URI}}/hcmRestApi/resources/11.13.18.05/workers?q=PersonNumber=25773',
                        'host' => ['{{URI}}'],
                        'path' => ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                        'query' => [
                            ['key' => 'q', 'value' => 'PersonNumber=25773'],
                            ['key' => 'expand', 'value' => 'names,addresses'],
                            ['key' => 'links', 'value' => 'self'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Folder',
                'item' => [
                    [
                        'name' => 'GetAll',
                        'request' => [
                            'method' => 'GET',
                            'url' => ['path' => ['fscmRestApi', 'resources', '11.13.18.05', 'purchaseOrders']],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Describe',
                'request' => [
                    'method' => 'GET',
                    'url' => ['path' => ['fscmRestApi', 'resources', '11.13.18.05', 'suppliers', '123', 'child', 'sites', 'describe']],
                ],
            ],
            [
                'name' => 'Create',
                'request' => [
                    'method' => 'POST',
                    'url' => ['path' => ['hcmRestApi', 'resources', '11.13.18.05', 'workers']],
                ],
            ],
            [
                'name' => 'Empty',
                'request' => ['method' => 'GET', 'header' => []],
            ],
        ],
    ];
}

test('the preview parses importable GET requests and reports what is skipped', function () {
    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => workersCollection()])
        ->assertOk()
        ->assertJsonCount(2, 'importable')
        ->assertJsonPath('importable.0.resource_path', '/hcmRestApi/resources/11.13.18.05/workers')
        ->assertJsonPath('importable.0.parameters.q', 'PersonNumber=25773')
        ->assertJsonPath('importable.0.parameters.expand', 'names,addresses')
        ->assertJsonPath('importable.0.semantic_resource_key', 'workers')
        ->assertJsonPath('importable.0.default_selected', true)
        ->assertJsonPath('importable.1.resource_path', '/fscmRestApi/resources/11.13.18.05/purchaseOrders')
        ->assertJsonPath('skipped.writes', 1)
        ->assertJsonPath('skipped.describe', 1)
        ->assertJsonPath('skipped.other', 1)
        ->assertJsonPath('security.oracle_calls', false)
        ->assertJsonPath('security.method', 'GET');
});

test('non-whitelisted query parameters are dropped', function () {
    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => workersCollection()])
        ->assertOk()
        ->assertJsonMissingPath('importable.0.parameters.links');
});

test('the owner imports the selected queries into their own library', function () {
    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => workersCollection(),
            'selected' => [0],
            'tenant_key' => 'client_x',
        ])
        ->assertRedirect(route('queries.index'));

    $query = Query::query()->sole();
    expect($query->user_id)->toBe($this->owner->id)
        ->and($query->mode)->toBe('single')
        ->and($query->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and($query->resource_path)->toBe('/hcmRestApi/resources/11.13.18.05/workers')
        ->and($query->parameters)->toBe(['q' => 'PersonNumber=25773', 'expand' => 'names,addresses']);
});

test('omitting the selection imports every importable query', function () {
    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => workersCollection(),
            'tenant_key' => 'client_x',
        ])
        ->assertRedirect(route('queries.index'));

    expect(Query::query()->count())->toBe(2);
});

test('an empty or non-Oracle collection imports nothing', function () {
    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => [
                'item' => [
                    ['name' => 'External', 'request' => ['method' => 'GET', 'url' => ['raw' => 'https://example.com/api/things']]],
                ],
            ],
            'tenant_key' => 'client_x',
        ])
        ->assertRedirect(route('queries.index'));

    expect(Query::query()->count())->toBe(0);
});

test('the collection payload is required', function () {
    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), [])
        ->assertStatus(422);
});

test('the import requires an Oracle environment owned by the user', function () {
    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => workersCollection(),
            'tenant_key' => 'unknown',
        ])
        ->assertInvalid('tenant_key');

    expect(Query::query()->count())->toBe(0);
});

test('an unsupported Postman schema is rejected', function () {
    $collection = workersCollection();
    $collection['info']['schema'] = 'https://schema.getpostman.com/json/collection/v1.0.0/collection.json';

    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => $collection])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('collection.info.schema');
});

test('unresolved variables and unsafe resource paths are skipped', function () {
    $collection = [
        'item' => [
            [
                'name' => 'Variable path',
                'request' => [
                    'method' => 'GET',
                    'url' => ['path' => ['hcmRestApi', 'resources', '11.13.18.05', 'workers', '{{workerId}}']],
                ],
            ],
            [
                'name' => 'Variable query',
                'request' => [
                    'method' => 'GET',
                    'url' => [
                        'path' => ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                        'query' => [['key' => 'q', 'value' => 'PersonNumber={{person}}']],
                    ],
                ],
            ],
            [
                'name' => 'Traversal',
                'request' => [
                    'method' => 'GET',
                    'url' => ['path' => ['hcmRestApi', 'resources', '11.13.18.05', '..', 'workers']],
                ],
            ],
        ],
    ];

    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => $collection])
        ->assertOk()
        ->assertJsonCount(0, 'importable')
        ->assertJsonPath('skipped.variables', 2)
        ->assertJsonPath('skipped.invalid_path', 1);
});

test('invalid supported parameters are ignored and integer parameters are typed', function () {
    $collection = [
        'item' => [[
            'name' => 'Limited workers',
            'request' => [
                'method' => 'GET',
                'url' => [
                    'path' => ['hcmRestApi', 'resources', '11.13.18.05', 'workers'],
                    'query' => [
                        ['key' => 'limit', 'value' => '25'],
                        ['key' => 'offset', 'value' => '0'],
                        ['key' => 'fields', 'value' => str_repeat('x', 2001)],
                    ],
                ],
            ],
        ]],
    ];

    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => $collection])
        ->assertOk()
        ->assertJsonPath('importable.0.parameters.limit', 25)
        ->assertJsonPath('importable.0.parameters.offset', 0)
        ->assertJsonMissingPath('importable.0.parameters.fields')
        ->assertJsonPath('skipped.invalid_parameters', 1);
});

test('tenant-specific child paths are warned and require explicit selection', function () {
    $identifier = str_repeat('A', 420);
    $path = "/hcmRestApi/resources/11.13.18.05/workers/{$identifier}/child/workRelationships";
    $collection = [
        'item' => [[
            'name' => 'Specific relationship',
            'request' => [
                'method' => 'GET',
                'url' => ['raw' => "https://example.oraclecloud.com{$path}"],
            ],
        ]],
    ];

    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => $collection])
        ->assertOk()
        ->assertJsonPath('importable.0.resource_path', $path)
        ->assertJsonPath('importable.0.tenant_specific_path', true)
        ->assertJsonPath('importable.0.default_selected', false)
        ->assertJsonPath('importable.0.warnings.0', 'source_host_removed')
        ->assertJsonPath('importable.0.warnings.1', 'tenant_specific_path');

    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => $collection,
            'tenant_key' => 'client_x',
        ])
        ->assertRedirect(route('queries.index'));

    expect(Query::query()->count())->toBe(0);

    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => $collection,
            'selected' => [0],
            'tenant_key' => 'client_x',
        ])
        ->assertRedirect(route('queries.index'));

    expect(Query::query()->sole()->resource_path)->toBe($path);
});

test('existing definitions are disabled in preview and are not imported twice', function () {
    $payload = [
        'collection' => workersCollection(),
        'selected' => [0],
        'tenant_key' => 'client_x',
    ];

    $this->actingAs($this->owner)
        ->post(route('queries.import'), $payload)
        ->assertRedirect(route('queries.index'));

    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => workersCollection()])
        ->assertOk()
        ->assertJsonPath('importable.0.already_imported', true)
        ->assertJsonPath('importable.0.default_selected', false);

    $this->actingAs($this->owner)
        ->post(route('queries.import'), $payload)
        ->assertRedirect(route('queries.index'));

    expect(Query::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'query.collection_imported')->count())->toBe(2);
});

test('the import is atomic when creating one selected query fails', function () {
    $calls = 0;

    $this->mock(SemanticLineageService::class, function (MockInterface $mock) use (&$calls): void {
        $mock->shouldReceive('syncQuery')
            ->twice()
            ->andReturnUsing(function () use (&$calls): void {
                $calls++;

                if ($calls === 2) {
                    throw new RuntimeException('Lineage failure');
                }
            });
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->owner)->post(route('queries.import'), [
        'collection' => workersCollection(),
        'selected' => [0, 1],
        'tenant_key' => 'client_x',
    ]))->toThrow(RuntimeException::class, 'Lineage failure');

    expect(Query::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'query.collection_imported')->count())->toBe(0);
});

test('preview and import never call Oracle and never persist Postman secrets', function () {
    Http::fake();
    $collection = workersCollection();
    $collection['auth'] = ['type' => 'basic', 'basic' => [['key' => 'password', 'value' => 'do-not-store']]];
    $collection['item'][0]['request']['header'] = [['key' => 'Authorization', 'value' => 'Bearer do-not-store']];
    $collection['item'][0]['request']['body'] = ['mode' => 'raw', 'raw' => '{"secret":"do-not-store"}'];

    $this->actingAs($this->owner)
        ->postJson(route('queries.import.preview'), ['collection' => $collection])
        ->assertOk();

    $this->actingAs($this->owner)
        ->post(route('queries.import'), [
            'collection' => $collection,
            'selected' => [0],
            'tenant_key' => 'client_x',
        ])
        ->assertRedirect(route('queries.index'));

    Http::assertNothingSent();

    expect(json_encode(Query::query()->sole()->toArray()))
        ->not->toContain('do-not-store')
        ->and(json_encode(AuditEvent::query()->where('action', 'query.collection_imported')->sole()->toArray()))
        ->not->toContain('do-not-store');
});
