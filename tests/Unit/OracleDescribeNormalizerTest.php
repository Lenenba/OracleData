<?php

use App\Services\OracleDescribeNormalizer;

test('Oracle describe metadata is canonicalized into stable fields and a stable hash', function () {
    $payload = [
        'Resources' => [
            'suppliers' => [
                'title' => 'Suppliers',
                'attributes' => [
                    [
                        'Name' => 'SupplierId',
                        'Type' => 'integer',
                        'Updatable' => false,
                        'links' => [['href' => 'https://oracle.example/describe']],
                    ],
                    [
                        'name' => 'Supplier',
                        'type' => 'string',
                        'mandatory' => true,
                        'maxLength' => 240,
                    ],
                ],
            ],
        ],
    ];

    $normalizer = new OracleDescribeNormalizer;
    $normalized = $normalizer->normalize($payload, 'suppliers');
    $reordered = $normalizer->normalize([
        'Resources' => [
            'suppliers' => [
                'attributes' => array_reverse($payload['Resources']['suppliers']['attributes']),
                'title' => 'Suppliers',
            ],
        ],
    ], 'suppliers');

    expect($normalized['title'])->toBe('Suppliers')
        ->and($normalized['fields'])->toBe(['Supplier', 'SupplierId'])
        ->and($normalized['attributes'][0])->toMatchArray([
            'name' => 'Supplier',
            'mandatory' => true,
            'max_length' => 240,
            'type' => 'string',
        ])
        ->and(json_encode($normalized['attributes'], JSON_THROW_ON_ERROR))->not->toContain('href')
        ->and($reordered['schema_hash'])->toBe($normalized['schema_hash']);
});

test('Oracle describe normalization rejects payloads without named attributes', function () {
    expect(fn () => (new OracleDescribeNormalizer)->normalize([
        'Resources' => ['suppliers' => ['attributes' => [['type' => 'string']]]],
    ], 'suppliers'))->toThrow(UnexpectedValueException::class);
});
