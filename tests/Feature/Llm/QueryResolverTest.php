<?php

use App\Services\QueryResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic', [
        'api_key' => 'sk-test',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-opus-4-8',
        'version' => '2023-06-01',
    ]);
});

/**
 * Fakes a Claude /v1/messages response whose single text block is $json.
 */
function fakeClaudeJson(array $json): void
{
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode($json)]],
        'stop_reason' => 'end_turn',
    ])]);
}

test('a single-resource request resolves to a structured query', function () {
    fakeClaudeJson([
        'mode' => 'single',
        'query' => [
            'resource' => 'suppliers',
            'fields' => ['Supplier', 'SupplierNumber'],
            'expand' => ['contacts', 'sites'],
            'limit' => 25,
        ],
    ]);

    $result = app(QueryResolver::class)->resolve(
        'liste des fournisseurs avec numéro et nom et leurs contacts et sites',
    );

    expect($result['mode'])->toBe('single')
        ->and($result['query']['resource'])->toBe('suppliers')
        ->and($result['query']['expand'])->toBe(['contacts', 'sites']);
});

test('a multi-resource analysis resolves to agent mode', function () {
    fakeClaudeJson([
        'mode' => 'agent',
        'plan' => 'Joindre les fournisseurs et les factures sur SupplierId puis agréger.',
    ]);

    $result = app(QueryResolver::class)->resolve('lier les fournisseurs et les factures et analyser');

    expect($result['mode'])->toBe('agent')
        ->and($result['plan'])->toContain('factures');
});

test('an ambiguous request resolves to a clarification question', function () {
    fakeClaudeJson(['mode' => 'clarify', 'question' => 'De quelle ressource parlez-vous ?']);

    $result = app(QueryResolver::class)->resolve('donne-moi les trucs');

    expect($result['mode'])->toBe('clarify')
        ->and($result['question'])->toBe('De quelle ressource parlez-vous ?');
});

test('the resolver sends the intent and the catalog context to Claude', function () {
    fakeClaudeJson(['mode' => 'clarify', 'question' => '?']);

    app(QueryResolver::class)->resolve('les fournisseurs');

    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        return str_contains($body, 'les fournisseurs') && str_contains($body, 'suppliers');
    });
});

test('an unparseable LLM answer raises a clean exception', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'not json at all']],
        'stop_reason' => 'end_turn',
    ])]);

    app(QueryResolver::class)->resolve('les fournisseurs');
})->throws(RuntimeException::class);
