<?php

use App\Services\OracleQueryTool;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('fusion.default', 'client_x');
    config()->set('fusion.tenants', [
        'client_x' => [
            'label' => 'Client X',
            'base_url' => 'https://client-x.fa.oraclecloud.com',
            'username' => 'svc_x',
            'password' => 'secret_x',
        ],
    ]);
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
