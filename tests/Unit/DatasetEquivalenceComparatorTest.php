<?php

use App\Services\DatasetEquivalenceComparator;

function datasetComparator(): DatasetEquivalenceComparator
{
    return new DatasetEquivalenceComparator('unit-test-data-quality-key', 'test-v1');
}

test('it creates deterministic value-free signed profiles', function () {
    $comparator = datasetComparator();
    $first = [[
        'PersonId' => 7,
        'DisplayName' => 'Alice Sensitive',
        'Email' => null,
        'Assignments' => [
            ['AssignmentId' => 12, 'Amount' => 10.5],
            ['AssignmentId' => 13, 'Amount' => 20.0],
        ],
    ]];
    $sameWithDifferentMapOrder = [[
        'Assignments' => [
            ['Amount' => 10.5, 'AssignmentId' => 12],
            ['Amount' => 20.0, 'AssignmentId' => 13],
        ],
        'Email' => null,
        'DisplayName' => 'Alice Sensitive',
        'PersonId' => 7,
    ]];

    $profile = $comparator->profile($first);
    $sameProfile = $comparator->profile($sameWithDifferentMapOrder);
    $serialized = json_encode($profile, JSON_THROW_ON_ERROR);

    expect($profile['dataset_hash'])
        ->toHaveLength(64)
        ->toBe($sameProfile['dataset_hash'])
        ->and($profile['profile_version'])->toBe(1)
        ->and($profile['hmac_version'])->toBe('test-v1')
        ->and($profile['row_count'])->toBe(1)
        ->and($serialized)->not->toContain('Alice Sensitive')
        ->and($serialized)->not->toContain('DisplayName')
        ->and($serialized)->not->toContain('AssignmentId')
        ->and($serialized)->not->toContain('10.5');
});

test('it separates value equality order and duplicate multiplicity', function () {
    $comparator = datasetComparator();
    $expected = [['id' => 1], ['id' => 2]];
    $reordered = [['id' => 2], ['id' => 1]];

    $strict = $comparator->compare($expected, $reordered);
    $unordered = $comparator->compare($expected, $reordered, ['order_sensitive' => false]);

    expect($strict['values_match'])->toBeTrue()
        ->and($strict['order_match'])->toBeFalse()
        ->and($strict['equivalent'])->toBeFalse()
        ->and($unordered['values_match'])->toBeTrue()
        ->and($unordered['order_match'])->toBeFalse()
        ->and($unordered['equivalent'])->toBeTrue();

    $leftDuplicates = [['id' => 1], ['id' => 1], ['id' => 2]];
    $rightDuplicates = [['id' => 1], ['id' => 2], ['id' => 2]];
    $duplicateSensitive = $comparator->compare($leftDuplicates, $rightDuplicates, [
        'order_sensitive' => false,
    ]);
    $duplicateInsensitive = $comparator->compare($leftDuplicates, $rightDuplicates, [
        'order_sensitive' => false,
        'duplicate_sensitive' => false,
    ]);

    expect($duplicateSensitive['duplicate_match'])->toBeFalse()
        ->and($duplicateSensitive['equivalent'])->toBeFalse()
        ->and($duplicateInsensitive['duplicate_match'])->toBeFalse()
        ->and($duplicateInsensitive['duplicates_required'])->toBeFalse()
        ->and($duplicateInsensitive['values_match'])->toBeTrue()
        ->and($duplicateInsensitive['equivalent'])->toBeTrue();
});

test('it distinguishes explicit null missing schema and aggregate changes', function () {
    $comparator = datasetComparator();
    $withNull = [['id' => 1, 'status' => null]];
    $withoutField = [['id' => 1]];

    $strict = $comparator->compare($withNull, $withoutField);
    $nullAndSchemaIgnored = $comparator->compare($withNull, $withoutField, [
        'compare_nulls' => false,
        'compare_schema' => false,
    ]);

    expect($strict['null_match'])->toBeFalse()
        ->and($strict['schema_match'])->toBeFalse()
        ->and($strict['equivalent'])->toBeFalse()
        ->and($nullAndSchemaIgnored['null_match'])->toBeFalse()
        ->and($nullAndSchemaIgnored['schema_match'])->toBeFalse()
        ->and($nullAndSchemaIgnored['values_match'])->toBeTrue()
        ->and($nullAndSchemaIgnored['equivalent'])->toBeTrue();

    $aggregate = $comparator->compare(
        [['amount' => 10], ['amount' => 20]],
        [['amount' => 10], ['amount' => 25]],
        [
            'order_sensitive' => false,
            'aggregates' => [['field' => 'amount', 'function' => 'sum']],
        ],
    );

    expect($aggregate['aggregate_match'])->toBeFalse()
        ->and($aggregate['mismatches']['aggregate_values'])->toBeGreaterThan(0)
        ->and($aggregate['counts']['aggregates'])->toBe(1);
});

test('it compares nested joins with independently configurable child order', function () {
    $comparator = datasetComparator();
    $expected = [[
        'id' => 1,
        'lines' => [
            ['line_id' => 10, 'amount' => 5],
            ['line_id' => 11, 'amount' => 7],
        ],
    ]];
    $reorderedChildren = [[
        'id' => 1,
        'lines' => [
            ['line_id' => 11, 'amount' => 7],
            ['line_id' => 10, 'amount' => 5],
        ],
    ]];

    $strict = $comparator->compare($expected, $reorderedChildren);
    $unorderedChildren = $comparator->compare($expected, $reorderedChildren, [
        'nested_order_sensitive' => false,
    ]);

    expect($strict['nested_match'])->toBeFalse()
        ->and($strict['equivalent'])->toBeFalse()
        ->and($unorderedChildren['nested_match'])->toBeTrue()
        ->and($unorderedChildren['equivalent'])->toBeTrue();
});

test('it compares a persisted profile and rejects tampering or configuration drift', function () {
    $comparator = datasetComparator();
    $options = [
        'order_sensitive' => false,
        'aggregates' => [['field' => 'amount', 'function' => 'avg']],
    ];
    $profile = $comparator->profile([['amount' => 10], ['amount' => 20]], $options);
    $report = $comparator->compareProfile(
        $profile,
        [['amount' => 20], ['amount' => 10]],
        $options,
    );

    expect($report['equivalent'])->toBeTrue()
        ->and($report['expected_fingerprint'])->toBe($profile['dataset_hash']);

    $tampered = $profile;
    $tampered['row_count'] = 999;

    expect(fn () => $comparator->compareProfile($tampered, [], $options))
        ->toThrow(InvalidArgumentException::class, 'signature');

    expect(fn () => $comparator->compareProfile(
        $profile,
        [['amount' => 10], ['amount' => 20]],
        ['order_sensitive' => true, 'aggregates' => [['field' => 'amount', 'function' => 'avg']]],
    ))->toThrow(InvalidArgumentException::class, 'configurations differ');
});
