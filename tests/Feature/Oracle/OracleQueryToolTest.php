<?php

use App\Enums\OracleExecutionPolicy;
use App\Services\OracleQueryTool;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(createConnectedUser([], [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
    ], [
        'identifier' => 'svc_x',
        'secret' => 'secret_x',
    ]));
});

test('run() executes a single GET with fields, q and orderBy', function () {
    Http::fake(['*' => Http::response([
        'items' => [['Supplier' => 'Acme', 'SupplierNumber' => 'S-100']],
        'count' => 1,
        'hasMore' => false,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier', 'SupplierNumber'],
        'q' => "Status='ACTIVE' AND Supplier LIKE '%Acme%'",
        'orderBy' => 'Supplier:asc',
        'limit' => 5,
    ]);

    expect($result['count'])->toBe(1)
        ->and($result['items'])->toHaveCount(1)
        ->and($result['resource']['key'])->toBe('suppliers')
        ->and($result['params']['fields'])->toBe('Supplier,SupplierNumber');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return str_starts_with($request->url(), 'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers')
            && $query['fields'] === 'Supplier,SupplierNumber'
            && $query['q'] === "Status='ACTIVE' AND Supplier LIKE '%Acme%'"
            && $query['orderBy'] === 'Supplier:asc'
            && $query['limit'] === '5';
    });
});

test('with fields and expand, Oracle gets expand and the parent is projected to the requested fields', function () {
    Http::fake(['*' => Http::response([
        'items' => [[
            'SupplierId' => 300,
            'SupplierPartyId' => 301,
            'Supplier' => 'Acme',
            'SupplierNumber' => '28784',
            'addresses' => ['items' => [['AddressLine1' => '1 rue']]],
            'sites' => ['items' => [['SiteName' => 'HQ']]],
        ]],
        'count' => 1,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier', 'SupplierNumber'],
        'expand' => ['addresses', 'sites'],
    ]);

    $item = $result['items'][0];

    // Oracle interdit fields+expand : on envoie expand (enfants complets), pas fields.
    expect($result['params'])->toHaveKey('expand', 'addresses,sites')
        ->and($result['params'])->not->toHaveKey('fields')
        // Projection serveur : parent restreint aux champs demandés, enfants conservés.
        ->and(array_keys($item))->toEqualCanonicalizing(['Supplier', 'SupplierNumber', 'addresses', 'sites'])
        ->and($item)->not->toHaveKey('SupplierId')
        ->and($item['addresses']['items'][0])->toHaveKey('AddressLine1');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['expand'] === 'addresses,sites' && ! isset($query['fields']);
    });
});

test('fields without expand are sent to Oracle and the parent is projected', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'Supplier' => 'Acme', 'SupplierNumber' => 'S-100']],
        'count' => 1,
    ])]);

    $item = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier', 'SupplierNumber'],
    ])['items'][0];

    expect(array_keys($item))->toEqualCanonicalizing(['Supplier', 'SupplierNumber'])
        ->and($item)->not->toHaveKey('SupplierId');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['fields'] === 'Supplier,SupplierNumber';
    });
});

test('purchase orders use the intent finder fallback when the primary collection is empty', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/purchaseOrders?*' => Http::sequence()
            ->push(['items' => [], 'count' => 0])
            ->push([
                'items' => [[
                    'POHeaderId' => 100,
                    'OrderNumber' => 'PO-100',
                    'Supplier' => 'Acme',
                    'Status' => 'Open',
                ]],
                'count' => 1,
                'hasMore' => false,
            ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'purchase_orders',
        'fields' => ['POHeaderId', 'OrderNumber', 'Supplier', 'Status'],
        'limit' => 25,
    ]);

    expect($result['count'])->toBe(1)
        ->and($result['items'][0]['OrderNumber'])->toBe('PO-100')
        ->and($result['calls'])->toHaveCount(2)
        ->and($result['calls'][0]['count'])->toBe(0)
        ->and($result['calls'][1]['params']['finder'])->toBe('findByIntent;Intent=POUser')
        ->and($result['params']['finder'])->toBe('findByIntent;Intent=POUser');

    Http::assertSentCount(2);
});

test('purchase orders can fall back to the LOV endpoint without incompatible REST fields', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/purchaseOrders?*' => Http::sequence()
            ->push(['items' => [], 'count' => 0])
            ->push(['items' => [], 'count' => 0])
            ->push(['items' => [], 'count' => 0]),
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/purchaseOrdersLOV?*' => Http::response([
            'items' => [[
                'POHeaderId' => 200,
                'OrderNumber' => 'PO-200',
                'Supplier' => 'Beta',
                'Status' => 'Closed',
            ]],
            'count' => 1,
            'hasMore' => false,
        ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'purchase_orders',
        'fields' => ['OrderNumber', 'Supplier', 'Status', 'Ordered'],
        'orderBy' => 'OrderNumber:asc',
        'limit' => 10,
    ]);

    expect($result['count'])->toBe(1)
        ->and($result['path'])->toBe('/fscmRestApi/resources/11.13.18.05/purchaseOrdersLOV')
        ->and($result['items'][0])->toEqual([
            'OrderNumber' => 'PO-200',
            'Supplier' => 'Beta',
            'Status' => 'Closed',
        ])
        ->and($result['calls'])->toHaveCount(4)
        ->and($result['calls'][3]['path'])->toBe('/fscmRestApi/resources/11.13.18.05/purchaseOrdersLOV')
        ->and($result['calls'][3]['params'])->not->toHaveKeys(['fields', 'orderBy']);
});

test('filtered fallbacks preserve q and skip compatibility attempts that remove it', function () {
    $filter = "Status='OPEN'";

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/purchaseOrdersLOV')) {
            return Http::response([
                'items' => [['OrderNumber' => 'PO-400', 'Status' => 'OPEN']],
                'count' => 1,
            ]);
        }

        return Http::response(['items' => [], 'count' => 0]);
    });

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'purchase_orders',
        'q' => $filter,
        'limit' => 25,
    ]);

    $requests = Http::recorded()->map(fn (array $exchange) => $exchange[0]);

    expect($result['path'])->toBe('/fscmRestApi/resources/11.13.18.05/purchaseOrdersLOV')
        ->and($result['calls'])->toHaveCount(2)
        ->and($requests)->toHaveCount(2);

    foreach ($requests as $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        expect($query['q'] ?? null)->toBe($filter)
            ->and($query)->not->toHaveKey('finder');
    }
});

test('exact execution returns an empty primary response without trying fallbacks', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0, 'hasMore' => false])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'purchase_orders',
        'limit' => 25,
    ], OracleExecutionPolicy::EXACT);

    expect($result['count'])->toBe(0)
        ->and($result['path'])->toBe('/fscmRestApi/resources/11.13.18.05/purchaseOrders')
        ->and($result['calls'])->toHaveCount(1);

    Http::assertSentCount(1);
});

test('a rejected field projection is retried without fields while preserving q', function () {
    $filter = "Status='OPEN'";

    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/purchaseOrders?*' => Http::sequence()
            ->pushStatus(400)
            ->push([
                'items' => [[
                    'POHeaderId' => 300,
                    'OrderNumber' => 'PO-300',
                    'Supplier' => 'Gamma',
                    'Ordered' => 1250,
                    'Status' => 'Open',
                ]],
                'count' => 1,
            ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'purchase_orders',
        'fields' => ['OrderNumber', 'Supplier', 'Ordered'],
        'q' => $filter,
        'limit' => 25,
    ]);

    expect($result['items'][0])->toEqual([
        'OrderNumber' => 'PO-300',
        'Supplier' => 'Gamma',
        'Ordered' => 1250,
    ])
        ->and($result['params'])->not->toHaveKey('fields')
        ->and($result['params']['q'])->toBe($filter)
        ->and($result['calls'])->toHaveCount(1)
        ->and($result['calls'][0]['params'])->not->toHaveKey('fields');

    Http::assertSentCount(2);

    $requests = Http::recorded()->map(fn (array $exchange) => $exchange[0])->values();

    foreach ($requests as $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        expect($query['q'] ?? null)->toBe($filter);
    }

    parse_str(parse_url($requests[0]->url(), PHP_URL_QUERY) ?: '', $firstQuery);
    parse_str(parse_url($requests[1]->url(), PHP_URL_QUERY) ?: '', $retryQuery);

    expect($firstQuery)->toHaveKey('fields')
        ->and($retryQuery)->not->toHaveKey('fields');
});

test('run() strips Oracle HATEOAS links from items, including nested children', function () {
    Http::fake(['*' => Http::response([
        'items' => [[
            'Supplier' => 'Acme',
            'addresses' => [
                'items' => [['AddressLine1' => '1 rue', 'links' => [['rel' => 'self']]]],
                'links' => [['rel' => 'self']],
            ],
            'links' => [['rel' => 'self'], ['rel' => 'canonical']],
        ]],
        'count' => 1,
    ])]);

    $item = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'expand' => ['addresses'],
    ])['items'][0];

    expect($item)->not->toHaveKey('links')
        ->and($item)->toHaveKey('Supplier')
        ->and($item['addresses'])->not->toHaveKey('links')
        ->and($item['addresses']['items'][0])->not->toHaveKey('links')
        ->and($item['addresses']['items'][0])->toHaveKey('AddressLine1');
});

test('a join fetches the related resource once and nests matching rows per parent', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers*' => Http::response([
            'items' => [
                ['SupplierNumber' => '79768', 'Supplier' => 'Acme'],
                ['SupplierNumber' => '80112', 'Supplier' => 'Beta'],
            ],
            'count' => 2,
        ]),
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/invoices*' => Http::response([
            'items' => [
                ['InvoiceId' => 10, 'SupplierNumber' => '79768', 'InvoiceNumber' => 'INV-10'],
                ['InvoiceId' => 11, 'SupplierNumber' => '79768', 'InvoiceNumber' => 'INV-11'],
                ['InvoiceId' => 12, 'SupplierNumber' => '80112', 'InvoiceNumber' => 'INV-12'],
            ],
            'count' => 3,
        ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'joins' => ['invoices'],
    ]);

    expect($result['items'][0]['invoices'])->toHaveCount(2)
        ->and($result['items'][0]['invoices'][0]['InvoiceNumber'])->toBe('INV-10')
        ->and($result['items'][1]['invoices'])->toHaveCount(1)
        ->and($result['calls'])->toHaveCount(2)
        ->and($result['calls'][1]['resource'])->toBe('invoices')
        ->and($result['query']['resource_key'])->toBe('suppliers')
        ->and($result['query']['joins'])->toBe('invoices');

    // Les numéros fournisseurs sont des chaînes côté Oracle : quotés même
    // s'ils ressemblent à des nombres.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/invoices')) {
            return true;
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['q'] === "SupplierNumber = '79768' OR SupplierNumber = '80112'";
    });
});

test('a join fails closed when the remote resource rejects its q filter', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/suppliers')) {
            return Http::response([
                'items' => [
                    ['SupplierId' => 11, 'Supplier' => 'Acme'],
                    ['SupplierId' => 22, 'Supplier' => 'Beta'],
                ],
                'count' => 2,
            ]);
        }

        return Http::response([], 400);
    });

    expect(fn () => app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'joins' => ['purchase_orders'],
    ]))->toThrow(RuntimeException::class);

    $remoteRequests = Http::recorded()
        ->map(fn (array $exchange) => $exchange[0])
        ->filter(fn ($request): bool => str_contains($request->url(), '/purchaseOrders'))
        ->values();

    expect($remoteRequests)->toHaveCount(2);

    foreach ($remoteRequests as $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        expect($query['q'] ?? null)->toBe('SupplierId = 11 OR SupplierId = 22');
    }
});

test('join fields are validated, fetched with the remote key, and projected', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers*' => Http::response([
            'items' => [['SupplierNumber' => '79768', 'Supplier' => 'Acme']],
            'count' => 1,
        ]),
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/invoices*' => Http::response([
            'items' => [['SupplierNumber' => '79768', 'InvoiceNumber' => 'INV-10', 'InvoiceAmount' => 100]],
            'count' => 1,
        ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'joins' => ['invoices'],
        'child_fields' => ['invoices' => ['InvoiceNumber', 'InvoiceAmount']],
    ]);

    // La clé de jointure (SupplierNumber) sert au regroupement puis est retirée.
    expect(array_keys($result['items'][0]['invoices'][0]))
        ->toEqualCanonicalizing(['InvoiceNumber', 'InvoiceAmount']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/invoices')) {
            return true;
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['fields'] === 'InvoiceNumber,InvoiceAmount,SupplierNumber';
    });
});

test('string join key values are quoted even when they look numeric', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/hcmRestApi/resources/11.13.18.05/workers*' => Http::response([
            'items' => [['PersonId' => 1, 'PersonNumber' => '300000048']],
            'count' => 1,
        ]),
        // absences expose personNumber en minuscule (clé distante de la jointure).
        'https://client-x.fa.oraclecloud.com/hcmRestApi/resources/11.13.18.05/absences*' => Http::response([
            'items' => [['personNumber' => '300000048', 'absenceType' => 'RTT']],
            'count' => 1,
        ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'workers',
        'joins' => ['absence_records'],
    ]);

    expect($result['items'][0]['absence_records'])->toHaveCount(1);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/absences')) {
            return true;
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['q'] === "personNumber = '300000048'";
    });
});

test('a join with no local key values attaches empty lists without a remote call', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers*' => Http::response([
            'items' => [['Supplier' => 'Acme']],
            'count' => 1,
        ]),
    ]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'joins' => ['invoices'],
    ]);

    expect($result['items'][0]['invoices'])->toBe([]);

    Http::assertSentCount(1);
});

test('an unknown join target is rejected before any Oracle call', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'joins' => ['ghosts'],
    ]);
})->throws(InvalidArgumentException::class);

test('an invented join field is rejected', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'joins' => ['invoices'],
        'child_fields' => ['invoices' => ['SecretColumn']],
    ]);
})->throws(InvalidArgumentException::class);

test('expand child fields are validated and projected on nested rows', function () {
    Http::fake(['*' => Http::response([
        'items' => [[
            'SupplierId' => 1,
            'Supplier' => 'Acme',
            'sites' => ['items' => [['SiteId' => 5, 'SupplierSite' => 'HQ', 'Email' => 'hq@acme.test']]],
        ]],
        'count' => 1,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'expand' => ['sites'],
        'child_fields' => ['sites' => ['Email']],
    ]);

    expect(array_keys($result['items'][0]['sites']['items'][0]))->toBe(['Email']);
});

test('an invented expand child field is rejected', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'expand' => ['sites'],
        'child_fields' => ['sites' => ['Bogus']],
    ]);
})->throws(InvalidArgumentException::class);

test('with fields, joined resources survive the parent projection', function () {
    Http::fake([
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers*' => Http::response([
            'items' => [['SupplierNumber' => '79768', 'Supplier' => 'Acme', 'Status' => 'ACTIVE']],
            'count' => 1,
        ]),
        'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/invoices*' => Http::response([
            'items' => [['SupplierNumber' => '79768', 'InvoiceNumber' => 'INV-10']],
            'count' => 1,
        ]),
    ]);

    $item = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier'],
        'joins' => ['invoices'],
    ])['items'][0];

    // La clé locale (SupplierNumber) est demandée à Oracle pour la jointure,
    // puis retirée du parent par la projection finale.
    expect(array_keys($item))->toEqualCanonicalizing(['Supplier', 'invoices']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/suppliers')) {
            return true;
        }

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['fields'] === 'Supplier,SupplierNumber';
    });
});

test('an unknown resource is rejected before any Oracle call', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', ['resource' => 'martians']);
})->throws(InvalidArgumentException::class);

test('an invented field is rejected', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier', 'SecretColumn'],
    ]);
})->throws(InvalidArgumentException::class);

test('an unknown expand child is rejected', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'expand' => ['ghosts'],
    ]);
})->throws(InvalidArgumentException::class);

test('a q filter referencing an unknown field is rejected', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'q' => "Bogus='X'",
    ]);
})->throws(InvalidArgumentException::class);

test('an orderBy on an unknown field is rejected', function () {
    Http::fake();

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'orderBy' => 'Bogus:asc',
    ]);
})->throws(InvalidArgumentException::class);

test('the limit is clamped and a default applied', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    app(OracleQueryTool::class)->run('client_x', ['resource' => 'workers', 'limit' => 99999]);

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query['limit'] === '500';
    });
});

test('no GET parameters are sent for the optional filters when omitted', function () {
    Http::fake(['*' => Http::response(['items' => []])]);

    app(OracleQueryTool::class)->run('client_x', ['resource' => 'workers']);

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return ! isset($query['q']) && ! isset($query['fields']) && ! isset($query['expand']) && ! isset($query['orderBy']);
    });
});

// ─── Champs découverts sur le tenant (hors catalogue) ────────────────────────

test('a field discovered on the tenant but absent from the catalog is accepted', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'Supplier' => 'Acme', 'InactiveDate' => null, 'links' => []]],
        'count' => 1,
        'hasMore' => false,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier', 'InactiveDate'],
        'limit' => 5,
    ]);

    expect($result['items'])->toHaveCount(1)
        ->and($result['params']['fields'])->toContain('InactiveDate');
});

test('a discovered field can be used for sorting and filtering too', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'InactiveDate' => '2024-01-01', 'links' => []]],
        'count' => 1,
        'hasMore' => false,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'q' => "InactiveDate='2024-01-01'",
        'orderBy' => 'InactiveDate:desc',
        'limit' => 5,
    ]);

    expect($result['params']['orderBy'])->toBe('InactiveDate:desc');
});

test('a field unknown to both the catalog and the tenant is still rejected', function () {
    Http::fake(['*' => Http::response([
        'items' => [['SupplierId' => 1, 'links' => []]],
        'count' => 1,
        'hasMore' => false,
    ])]);

    app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['TotallyInvented'],
    ]);
})->throws(InvalidArgumentException::class);

test('a discovered child field outside the catalog is accepted', function () {
    Http::fake(['*' => Http::response([
        'items' => [[
            'SupplierId' => 1,
            'sites' => ['items' => [['SiteId' => 7, 'BrandNewSiteField' => 'x', 'links' => []]]],
            'links' => [],
        ]],
        'count' => 1,
        'hasMore' => false,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'expand' => ['sites'],
        'child_fields' => ['sites' => ['BrandNewSiteField']],
    ]);

    expect($result['items'])->toHaveCount(1);
});
