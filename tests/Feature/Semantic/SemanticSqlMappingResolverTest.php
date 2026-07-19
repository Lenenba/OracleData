<?php

use App\Enums\SemanticSqlMappingStatus;
use App\Models\SemanticResource;
use App\Services\OracleQueryTool;
use App\Services\SemanticCatalogSynchronizer;
use App\Services\SemanticCatalogVersioner;
use App\Services\SemanticSqlMappingResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app(SemanticCatalogSynchronizer::class)->synchronize();
    $this->semanticSqlResolver = app(SemanticSqlMappingResolver::class);
});

test('the SQL mapping resolver returns only the single exact catalog mapping', function () {
    $mapping = $this->semanticSqlResolver->resolve('poz_suppliers_v', 'vendor_id');

    expect($mapping)->not->toBeNull()
        ->and($mapping['resource']->resource_key)->toBe('suppliers')
        ->and($mapping['field']->source_name)->toBe('SupplierId')
        ->and($this->semanticSqlResolver->resolveField(
            $mapping['resource'],
            'vendor_id',
            'sites',
        ))->toBeNull();
});

test('the SQL mapping resolver fails closed for unknown inactive derived ambiguous and unsafe mappings', function () {
    expect($this->semanticSqlResolver->resolve('UNKNOWN_TABLE', 'VENDOR_ID'))->toBeNull();

    $supplier = SemanticResource::query()->where('resource_key', 'suppliers')->firstOrFail();
    $supplierId = $supplier->fields()->where('source_name', 'SupplierId')->firstOrFail();
    $supplierId->update(['sql_mapping_status' => SemanticSqlMappingStatus::Derived]);

    expect($this->semanticSqlResolver->resolve('POZ_SUPPLIERS_V', 'VENDOR_ID'))->toBeNull();

    $supplierId->update(['sql_mapping_status' => SemanticSqlMappingStatus::Exact]);
    $supplier->update(['is_active' => false]);

    expect($this->semanticSqlResolver->resolveResource('POZ_SUPPLIERS_V'))->toBeNull();

    $supplier->update(['is_active' => true]);
    SemanticResource::factory()->create([
        'sql_table' => 'POZ_SUPPLIERS_V',
        'sql_mapping_status' => SemanticSqlMappingStatus::Exact,
    ]);

    expect($this->semanticSqlResolver->resolveResource('POZ_SUPPLIERS_V'))->toBeNull()
        ->and(fn () => $this->semanticSqlResolver->resolveResource('POZ_SUPPLIERS_V; DROP TABLE users'))
        ->toThrow(InvalidArgumentException::class);
});

test('Oracle execution rejects inactive governed resources and fields before HTTP', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $this->actingAs($user);
    Http::fake();
    $supplier = SemanticResource::query()->where('resource_key', 'suppliers')->firstOrFail();
    $supplier->update(['is_active' => false]);
    app(SemanticCatalogVersioner::class)->capture($user, 'Ressource désactivée pour le test');

    expect(fn () => app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
    ]))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();

    $supplier->update(['is_active' => true]);
    $inactiveField = $supplier->fields()->where('source_name', 'SupplierNumber')->firstOrFail();
    $inactiveField->update(['is_active' => false]);
    app(SemanticCatalogVersioner::class)->capture($user, 'Champ désactivé pour le test');

    expect(fn () => app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['SupplierNumber'],
    ]))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('Oracle execution without fields projects only active governed fields', function () {
    $user = createConnectedUser([], ['key' => 'client_x']);
    $this->actingAs($user);
    $supplier = SemanticResource::query()->where('resource_key', 'suppliers')->firstOrFail();
    $inactiveField = $supplier->fields()->where('source_name', 'SupplierNumber')->firstOrFail();
    $inactiveField->update(['is_active' => false]);
    app(SemanticCatalogVersioner::class)->capture($user, 'Projection gouvernée pour le test');
    Http::fake(['*' => Http::response([
        'items' => [[
            'SupplierId' => 42,
            'Supplier' => 'Acme',
            'SupplierNumber' => 'INACTIVE-1',
            'DiscoveredButUngoverned' => 'secret',
        ]],
        'count' => 1,
        'hasMore' => false,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'limit' => 5,
    ]);

    expect($result['params']['fields'])->not->toContain('SupplierNumber')
        ->and($result['items'][0])->toHaveKeys(['SupplierId', 'Supplier'])
        ->not->toHaveKeys(['SupplierNumber', 'DiscoveredButUngoverned'])
        ->and($result['query']['fields'])->not->toContain('SupplierNumber')
        ->not->toContain('DiscoveredButUngoverned');

    Http::assertSentCount(1);
});
