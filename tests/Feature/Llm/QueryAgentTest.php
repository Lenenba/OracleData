<?php

use App\Services\QueryAgent;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic', [
        'api_key' => 'sk-test',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-opus-4-8',
        'version' => '2023-06-01',
    ]);
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

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function claudeToolUse(string $id, string $name, array $input): array
{
    return [
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
        'stop_reason' => 'tool_use',
    ];
}

test('the agent queries Oracle then submits a composed result', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(claudeToolUse('tu1', 'oracle_query', ['resource' => 'suppliers', 'fields' => ['Supplier']]))
            ->push(claudeToolUse('tu2', 'submit_result', [
                'columns' => ['Supplier', 'TotalInvoiced'],
                'rows' => [['Supplier' => 'Acme', 'TotalInvoiced' => 1200]],
                'analysis' => 'Acme est le premier fournisseur par montant facturé.',
            ])),
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [['Supplier' => 'Acme']], 'count' => 1]),
    ]);

    $result = app(QueryAgent::class)->run('client_x', 'fournisseurs et factures, total par fournisseur');

    expect($result['columns'])->toBe(['Supplier', 'TotalInvoiced'])
        ->and($result['rows'])->toHaveCount(1)
        ->and($result['analysis'])->toContain('Acme')
        ->and($result['oracleCalls'])->toHaveCount(1)
        ->and($result['oracleCalls'][0]['resource'])->toBe('suppliers');
});

test('the agent can chain calls across two resources before submitting', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(claudeToolUse('a', 'oracle_query', ['resource' => 'suppliers']))
            ->push(claudeToolUse('b', 'oracle_query', ['resource' => 'invoices']))
            ->push(claudeToolUse('c', 'submit_result', [
                'columns' => ['Supplier'],
                'rows' => [['Supplier' => 'Acme']],
                'analysis' => 'ok',
            ])),
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [], 'count' => 0]),
    ]);

    $result = app(QueryAgent::class)->run('client_x', 'lier fournisseurs et factures');

    expect($result['oracleCalls'])->toHaveCount(2)
        ->and(array_column($result['oracleCalls'], 'resource'))->toBe(['suppliers', 'invoices']);
});

test('the agent advertises the oracle_query and submit_result tools', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(claudeToolUse('z', 'submit_result', ['columns' => [], 'rows' => [], 'analysis' => ''])),
    ]);

    app(QueryAgent::class)->run('client_x', 'analyse');

    Http::assertSent(function ($request) {
        if (! str_starts_with($request->url(), 'https://api.anthropic.com')) {
            return false;
        }

        $names = array_column($request['tools'] ?? [], 'name');

        return in_array('oracle_query', $names, true) && in_array('submit_result', $names, true);
    });
});

test('the agent stops with a clean error if it never submits a result', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->whenEmpty(Http::response(claudeToolUse('loop', 'oracle_query', ['resource' => 'suppliers']))),
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [], 'count' => 0]),
    ]);

    app(QueryAgent::class)->run('client_x', 'boucle infinie');
})->throws(RuntimeException::class);
