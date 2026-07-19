<?php

use App\Enums\DataQualityAssertionType;
use App\Models\QueryTemplateVersion;
use App\Services\DataQualityAssertionEvaluator;
use App\Services\DatasetEquivalenceComparator;

function assertionEvaluator(): DataQualityAssertionEvaluator
{
    return new DataQualityAssertionEvaluator(
        new DatasetEquivalenceComparator('unit-test-data-quality-key', 'test-v1'),
    );
}

/** @return array<string, mixed> */
function qualityRule(
    string $id,
    DataQualityAssertionType $type,
    array $config = [],
    bool $required = true,
    bool $enabled = true,
): array {
    return [
        'id' => $id,
        'name' => 'Technical rule '.$id,
        'type' => $type->value,
        'enabled' => $enabled,
        'required' => $required,
        'config' => $config,
    ];
}

test('it evaluates all configurable assertions without exposing row values', function () {
    $rules = [
        qualityRule('has_rows', DataQualityAssertionType::NonEmpty),
        qualityRule('bounded_rows', DataQualityAssertionType::RowCountRange, ['min' => 1, 'max' => 5]),
        qualityRule('unique_people', DataQualityAssertionType::Unique, ['fields' => ['PersonId']]),
        qualityRule('required_values', DataQualityAssertionType::RequiredFields, [
            'fields' => ['PersonId', 'Assignments.AssignmentId'],
        ]),
        qualityRule('known_status', DataQualityAssertionType::AllowedValues, [
            'field' => 'Status',
            'values' => ['ACTIVE', 'INACTIVE'],
        ]),
        qualityRule('fast_enough', DataQualityAssertionType::MaxDuration, ['max_ms' => 500]),
    ];
    $rows = [
        [
            'PersonId' => 1,
            'DisplayName' => 'Alice Sensitive',
            'Status' => 'ACTIVE',
            'Assignments' => ['items' => [['AssignmentId' => 100]]],
        ],
        [
            'PersonId' => 2,
            'DisplayName' => 'Bob Sensitive',
            'Status' => 'INACTIVE',
            'Assignments' => ['items' => [['AssignmentId' => 101]]],
        ],
    ];

    $report = assertionEvaluator()->evaluate($rules, $rows, 120);
    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['status'])->toBe('passed')
        ->and($report['passed'])->toBeTrue()
        ->and($report['score'])->toBe(100.0)
        ->and($report['required_failed'])->toBeFalse()
        ->and($report['rules_hash'])->toBe(QueryTemplateVersion::qualityRulesHash($rules))
        ->and($report['assertions'])->toHaveCount(6)
        ->and(collect($report['assertions'])->every(fn (array $result): bool => $result['passed']))->toBeTrue()
        ->and($serialized)->not->toContain('Alice Sensitive')
        ->and($serialized)->not->toContain('Bob Sensitive')
        ->and($serialized)->not->toContain('ACTIVE')
        ->and($serialized)->not->toContain('PersonId')
        ->and($serialized)->not->toContain('AssignmentId');
});

test('it reports required failures and a deterministic score with safe metrics', function () {
    $rules = [
        qualityRule('unique_people', DataQualityAssertionType::Unique, ['fields' => ['PersonId']]),
        qualityRule('required_email', DataQualityAssertionType::RequiredFields, ['fields' => ['Email']]),
        qualityRule('known_status', DataQualityAssertionType::AllowedValues, [
            'field' => 'Status',
            'values' => ['ACTIVE'],
        ]),
        qualityRule('fast_enough', DataQualityAssertionType::MaxDuration, ['max_ms' => 50]),
    ];
    $rows = [
        ['PersonId' => 1, 'Email' => null, 'Status' => 'FORBIDDEN_SECRET'],
        ['PersonId' => 1, 'Status' => 'ACTIVE'],
    ];

    $report = assertionEvaluator()->evaluate($rules, $rows, 75);
    $byType = collect($report['assertions'])->keyBy('type');
    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['status'])->toBe('failed')
        ->and($report['passed'])->toBeFalse()
        ->and($report['required_failed'])->toBeTrue()
        ->and($report['score'])->toBe(0.0)
        ->and($byType[DataQualityAssertionType::Unique->value]['code'])->toBe('duplicate_key')
        ->and($byType[DataQualityAssertionType::RequiredFields->value]['metrics']['violation_count'])->toBe(2)
        ->and($byType[DataQualityAssertionType::AllowedValues->value]['metrics']['violation_count'])->toBe(1)
        ->and($byType[DataQualityAssertionType::MaxDuration->value]['code'])->toBe('duration_exceeded')
        ->and($serialized)->not->toContain('FORBIDDEN_SECRET')
        ->and($serialized)->not->toContain('Email');
});

test('it distinguishes optional failures disabled rules and invalid rule errors', function () {
    $optional = [
        qualityRule('optional_rows', DataQualityAssertionType::NonEmpty, required: false),
        qualityRule('disabled_duration', DataQualityAssertionType::MaxDuration, ['max_ms' => 1], enabled: false),
    ];
    $optionalReport = assertionEvaluator()->evaluate($optional, [], 100);

    expect($optionalReport['status'])->toBe('passed')
        ->and($optionalReport['passed'])->toBeTrue()
        ->and($optionalReport['score'])->toBe(0.0)
        ->and($optionalReport['required_failed'])->toBeFalse()
        ->and($optionalReport['assertions'])->toHaveCount(1);

    $invalid = [[
        'id' => 'invalid',
        'name' => 'Invalid',
        'type' => 'unknown_type',
        'enabled' => true,
        'required' => true,
        'config' => [],
    ]];
    $invalidReport = assertionEvaluator()->evaluate($invalid, [], 0);

    expect($invalidReport['status'])->toBe('error')
        ->and($invalidReport['passed'])->toBeFalse()
        ->and($invalidReport['required_failed'])->toBeTrue()
        ->and($invalidReport['assertions'][0]['code'])->toBe('invalid_rule');
});

test('it evaluates multiple precomputed reference reports by immutable identifier', function () {
    $rules = [
        qualityRule('baseline', DataQualityAssertionType::ReferenceEquivalence, [
            'reference_dataset_id' => 10,
        ]),
        qualityRule('order_case', DataQualityAssertionType::ReferenceEquivalence, [
            'reference_dataset_id' => 20,
        ]),
    ];
    $matching = [
        'equivalent' => true,
        'schema_match' => true,
        'values_match' => true,
        'order_match' => true,
        'duplicate_match' => true,
        'null_match' => true,
        'aggregate_match' => true,
        'nested_match' => true,
        'mismatches' => ['schema' => 0],
        'counts' => ['expected_rows' => 2, 'actual_rows' => 2],
    ];
    $differentOrder = [
        ...$matching,
        'equivalent' => false,
        'order_match' => false,
        'mismatches' => ['order_positions' => 2],
    ];

    $report = assertionEvaluator()->evaluate($rules, [], 10, [
        10 => $matching,
        20 => $differentOrder,
    ]);

    expect($report['status'])->toBe('failed')
        ->and($report['score'])->toBe(50.0)
        ->and($report['assertions'][0]['passed'])->toBeTrue()
        ->and($report['assertions'][1]['passed'])->toBeFalse()
        ->and($report['assertions'][1]['code'])->toBe('reference_mismatch')
        ->and($report['assertions'][1]['metrics']['mismatch_order_positions'])->toBe(2);

    $missing = assertionEvaluator()->evaluate([$rules[0]], [], 10);

    expect($missing['status'])->toBe('error')
        ->and($missing['assertions'][0]['code'])->toBe('reference_unavailable');
});
