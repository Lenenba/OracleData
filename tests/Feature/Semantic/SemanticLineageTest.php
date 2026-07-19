<?php

use App\Models\Query;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use App\Models\SemanticCatalogVersion;
use App\Services\QueryExecutionRecorder;
use App\Services\SemanticCatalogSynchronizer;
use App\Services\SemanticLineageService;

beforeEach(function () {
    app(SemanticCatalogSynchronizer::class)->synchronize();
    $this->semanticLineage = app(SemanticLineageService::class);
});

test('query and template version lineage captures resources fields filters sorts expands and joins', function () {
    $query = Query::factory()->create();
    $queryDefinition = [
        'resource_key' => 'suppliers',
        'fields' => ['SupplierId', 'Supplier'],
        'q' => "Status='ACTIVE'",
        'orderBy' => 'Supplier:asc',
        'expand' => ['sites'],
        'joins' => ['invoices'],
        'child_fields' => [
            'sites' => ['SiteId'],
            'invoices' => ['InvoiceId'],
        ],
    ];

    $this->semanticLineage->syncQuery($query, $queryDefinition);

    $queryDependencies = $query->semanticResources()->get();
    $queryTuples = $queryDependencies->map(fn ($dependency): string => implode('|', [
        $dependency->resource_key,
        $dependency->child_key,
        $dependency->field_key,
        $dependency->usage->value,
    ]));
    $publishedVersionId = SemanticCatalogVersion::query()->whereNotNull('published_slot')->value('id');

    expect($queryTuples)->toContain(
        'suppliers|||resource',
        'suppliers||SupplierId|select',
        'suppliers||Status|filter',
        'suppliers||Supplier|sort',
        'suppliers|sites||expand',
        'suppliers|sites|SiteId|select',
        'suppliers|invoices||join_source',
        'invoices|||join_target',
        'invoices||InvoiceId|select',
    )->and($queryDependencies->pluck('semantic_catalog_version_id')->unique()->all())
        ->toBe([$publishedVersionId]);

    $template = QueryTemplate::factory()->create();
    $templateDefinition = [
        'parameters' => [
            'resource_key' => 'purchase_orders',
            'fields' => ['POHeaderId'],
            'expand' => ['lines'],
            'child_fields' => ['lines' => ['LineId']],
        ],
    ];
    $translations = [];
    $templateVersion = QueryTemplateVersion::factory()->for($template)->create([
        'definition' => $templateDefinition,
        'translations' => $translations,
        'content_hash' => QueryTemplateVersion::contentHash($templateDefinition, $translations),
    ]);

    $this->semanticLineage->syncTemplateVersion($templateVersion, $templateDefinition);

    $templateTuples = $templateVersion->semanticResources()->get()
        ->map(fn ($dependency): string => implode('|', [
            $dependency->resource_key,
            $dependency->child_key,
            $dependency->field_key,
            $dependency->usage->value,
        ]));

    expect($templateTuples)->toContain(
        'purchase_orders|||resource',
        'purchase_orders||POHeaderId|select',
        'purchase_orders|lines||expand',
        'purchase_orders|lines|LineId|select',
    );
});

test('execution records freeze the published catalog version and exact Oracle call lineage', function () {
    $user = createConnectedUser();
    $tenant = $user->oracleTenants()->sole();
    $query = Query::factory()->for($user)->create([
        'oracle_tenant_id' => $tenant->id,
        'tenant_key' => $tenant->key,
        'parameters' => [
            'resource_key' => 'suppliers',
            'fields' => ['SupplierId'],
        ],
    ]);
    $this->semanticLineage->syncQuery($query, $query->parameters);
    $payload = [
        'error' => null,
        'items' => [['SupplierId' => 1], ['SupplierId' => 2]],
        'oracleCalls' => [[
            'resource' => 'suppliers',
            'params' => ['fields' => ['SupplierId']],
            'count' => 2,
        ]],
    ];

    $execution = app(QueryExecutionRecorder::class)->recordQueryRun(
        $user,
        $query,
        $tenant->key,
        $payload,
        now()->subSecond(),
        42,
    );
    $publishedVersionId = SemanticCatalogVersion::query()->whereNotNull('published_slot')->value('id');
    $lineage = collect($execution->semantic_lineage);

    expect($execution->semantic_catalog_version_id)->toBe($publishedVersionId)
        ->and($execution->rows_count)->toBe(2)
        ->and($lineage)->toHaveCount(2)
        ->and($lineage->firstWhere('usage', 'resource'))->toMatchArray([
            'resource_key' => 'suppliers',
            'call_index' => 0,
            'rows_count' => 2,
        ])
        ->and($lineage->firstWhere('usage', 'select'))->toMatchArray([
            'resource_key' => 'suppliers',
            'field_key' => 'SupplierId',
            'call_index' => 0,
            'rows_count' => 2,
        ]);
});
