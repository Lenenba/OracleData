<?php

use App\Models\QueryTemplate;
use App\Services\QueryTemplateAuditSanitizer;

test('template parameter audit policies clear mask fingerprint or omit values', function () {
    config()->set('audit.query_template_parameters.hmac.key', 'dedicated-audit-test-key');
    config()->set('audit.query_template_parameters.hmac.version', 'v-test');

    $template = new QueryTemplate([
        'slug' => 'supplier-invoices',
        'parameter_definitions' => [
            ['key' => 'amount', 'audit' => 'clear'],
            ['key' => 'supplier', 'audit' => ['mode' => 'masked']],
            ['key' => 'reference', 'audit_mode' => 'hmac'],
            ['key' => 'free_text', 'audit' => 'omit'],
        ],
    ]);

    $sanitized = app(QueryTemplateAuditSanitizer::class)->sanitize($template, [
        'amount' => 2500.5,
        'supplier' => 'masked-raw-secret',
        'reference' => 'fingerprinted-raw-secret',
        'free_text' => 'omitted-raw-secret',
        'undeclared' => 'undeclared-raw-secret',
    ]);
    $serialized = json_encode($sanitized, JSON_THROW_ON_ERROR);
    $repeated = app(QueryTemplateAuditSanitizer::class)->sanitize($template, [
        'reference' => 'fingerprinted-raw-secret',
    ]);
    $changed = app(QueryTemplateAuditSanitizer::class)->sanitize($template, [
        'reference' => 'another-value',
    ]);

    expect($sanitized)->toHaveKey('amount', 2500.5)
        ->and($sanitized)->toHaveKey('supplier', '[masked]')
        ->and($sanitized['reference'])->toMatch('/^hmac-sha256:v-test:[a-f0-9]{64}$/')
        ->and($sanitized)->not->toHaveKeys(['free_text', 'undeclared'])
        ->and($serialized)->not->toContain('masked-raw-secret')
        ->and($serialized)->not->toContain('fingerprinted-raw-secret')
        ->and($serialized)->not->toContain('omitted-raw-secret')
        ->and($serialized)->not->toContain('undeclared-raw-secret')
        ->and($repeated['reference'])->toBe($sanitized['reference'])
        ->and($changed['reference'])->not->toBe($sanitized['reference']);
});

test('missing and unknown audit policies are omitted even with a permissive global default', function () {
    config()->set('audit.query_template_parameters.default_mode', 'clear');

    $template = new QueryTemplate([
        'slug' => 'fail-closed-audit',
        'parameter_definitions' => [
            ['key' => 'missing_policy'],
            ['key' => 'unknown_policy', 'audit' => 'plaintext'],
            ['key' => 'unknown_nested_policy', 'audit' => ['mode' => 'unexpected']],
        ],
    ]);

    $sanitized = app(QueryTemplateAuditSanitizer::class)->sanitize($template, [
        'missing_policy' => 'first-raw-secret',
        'unknown_policy' => 'second-raw-secret',
        'unknown_nested_policy' => 'third-raw-secret',
    ]);

    expect($sanitized)->toBe([])
        ->and(json_encode($sanitized, JSON_THROW_ON_ERROR))->not->toContain('raw-secret');
});
