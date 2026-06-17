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

test('run() executes a single GET with fields, expand, q and orderBy', function () {
    Http::fake(['*' => Http::response([
        'items' => [['Supplier' => 'Acme', 'SupplierNumber' => 'S-100']],
        'count' => 1,
        'hasMore' => false,
    ])]);

    $result = app(OracleQueryTool::class)->run('client_x', [
        'resource' => 'suppliers',
        'fields' => ['Supplier', 'SupplierNumber'],
        'expand' => ['contacts', 'sites'],
        'q' => "Status='ACTIVE' AND Supplier LIKE '%Acme%'",
        'orderBy' => 'Supplier:asc',
        'limit' => 5,
    ]);

    expect($result['count'])->toBe(1)
        ->and($result['items'])->toHaveCount(1)
        ->and($result['resource']['key'])->toBe('suppliers')
        ->and($result['params']['fields'])->toBe('Supplier,SupplierNumber')
        ->and($result['params']['expand'])->toBe('contacts,sites');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return str_starts_with($request->url(), 'https://client-x.fa.oraclecloud.com/fscmRestApi/resources/11.13.18.05/suppliers')
            && $query['fields'] === 'Supplier,SupplierNumber'
            && $query['expand'] === 'contacts,sites'
            && $query['q'] === "Status='ACTIVE' AND Supplier LIKE '%Acme%'"
            && $query['orderBy'] === 'Supplier:asc'
            && $query['limit'] === '5';
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
