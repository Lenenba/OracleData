<?php

use App\Domain\Query\Filter\FilterCondition;
use App\Domain\Query\Filter\FilterGroup;
use App\Domain\Query\Filter\FilterOperator;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\ResourceDefinition;

function phaseOneFilterResource(): ResourceDefinition
{
    return new ResourceDefinition(
        id: 'catalog.people',
        name: 'people',
        module: 'test',
        label: 'People',
        collectionPath: '/people',
        itemPath: '/people/{PersonId}',
        apiVersion: '1',
        capabilities: QueryCapabilities::standard(),
        fields: [
            FieldDefinition::identifier('PersonId'),
            FieldDefinition::string('DisplayName'),
            FieldDefinition::string('Secret', queryable: false),
            new FieldDefinition('Age', type: 'integer'),
            new FieldDefinition('Ratio', type: 'number'),
            FieldDefinition::date('HireDate'),
            FieldDefinition::datetime('UpdatedAt'),
            FieldDefinition::boolean('Active'),
        ],
    );
}

test('structured filter accepts governed fields operators values and bounded groups', function () {
    $payload = [
        'logic' => 'and',
        'conditions' => [
            ['field' => 'DisplayName', 'operator' => 'starts_with', 'value' => 'Ada'],
            [
                'logic' => 'or',
                'conditions' => [
                    ['field' => 'Age', 'operator' => 'gte', 'value' => 18],
                    ['field' => 'Active', 'operator' => 'eq', 'value' => true],
                ],
            ],
        ],
    ];

    expect(FilterGroup::fromArray($payload, phaseOneFilterResource())->toArray())
        ->toBe($payload);
});

test('structured filter rejects a raw Oracle q fragment', function () {
    expect(fn () => FilterGroup::fromArray(
        ['q' => "DisplayName='Ada'"],
        phaseOneFilterResource(),
    ))->toThrow(InvalidArgumentException::class);
});

test('structured filter rejects unknown and non queryable fields', function (string $field) {
    expect(fn () => FilterGroup::fromArray([
        'logic' => 'and',
        'conditions' => [
            ['field' => $field, 'operator' => 'eq', 'value' => 'value'],
        ],
    ], phaseOneFilterResource()))->toThrow(InvalidArgumentException::class, $field);
})->with(['UnknownField', 'Secret']);

test('structured filter rejects operators incompatible with the field type', function (
    string $field,
    string $operator,
    mixed $value,
) {
    expect(fn () => FilterGroup::fromArray([
        'logic' => 'and',
        'conditions' => [
            ['field' => $field, 'operator' => $operator, 'value' => $value],
        ],
    ], phaseOneFilterResource()))->toThrow(InvalidArgumentException::class, $operator);
})->with([
    ['Age', 'contains', 42],
    ['Active', 'gt', true],
    ['DisplayName', 'gte', 'Ada'],
]);

test('structured filter rejects values incompatible with the field type', function (
    string $field,
    string $operator,
    mixed $value,
) {
    expect(fn () => FilterGroup::fromArray([
        'logic' => 'and',
        'conditions' => [
            ['field' => $field, 'operator' => $operator, 'value' => $value],
        ],
    ], phaseOneFilterResource()))->toThrow(InvalidArgumentException::class);
})->with([
    ['Age', 'eq', '18'],
    ['Active', 'eq', 'true'],
    ['HireDate', 'eq', '2026-02-30'],
    ['UpdatedAt', 'eq', 'not-a-datetime'],
    ['UpdatedAt', 'eq', '2026-02-30T12:00:00Z'],
    ['DisplayName', 'in', []],
    ['Ratio', 'eq', NAN],
    ['Ratio', 'eq', INF],
    ['Ratio', 'eq', -INF],
]);

test('structured filter enforces the in-list limit at its exact boundary', function () {
    $accepted = range(1, FilterCondition::MAX_LIST_VALUES);
    $rejected = range(1, FilterCondition::MAX_LIST_VALUES + 1);

    $payload = fn (array $values): array => [
        'logic' => 'and',
        'conditions' => [
            ['field' => 'Age', 'operator' => 'in', 'value' => $values],
        ],
    ];

    expect(FilterGroup::fromArray($payload($accepted), phaseOneFilterResource())->toArray())
        ->toBe($payload($accepted));

    expect(fn () => FilterGroup::fromArray($payload($rejected), phaseOneFilterResource()))
        ->toThrow(InvalidArgumentException::class, (string) FilterCondition::MAX_LIST_VALUES);
});

test('FilterCondition constructor cannot bypass field queryability governance', function () {
    expect(fn () => new FilterCondition(
        field: FieldDefinition::string('Secret', queryable: false),
        operator: FilterOperator::Equal,
        value: 'classified',
    ))->toThrow(InvalidArgumentException::class, 'Secret');
});

test('nullary filter operators reject a supplied value', function () {
    expect(fn () => FilterGroup::fromArray([
        'logic' => 'and',
        'conditions' => [
            ['field' => 'DisplayName', 'operator' => 'is_null', 'value' => 'unexpected'],
        ],
    ], phaseOneFilterResource()))->toThrow(InvalidArgumentException::class);
});

test('structured filter bounds logical nesting depth', function () {
    $condition = ['field' => 'Age', 'operator' => 'eq', 'value' => 18];
    $payload = [
        'logic' => 'and',
        'conditions' => [[
            'logic' => 'or',
            'conditions' => [[
                'logic' => 'and',
                'conditions' => [[
                    'logic' => 'or',
                    'conditions' => [$condition],
                ]],
            ]],
        ]],
    ];

    expect(fn () => FilterGroup::fromArray($payload, phaseOneFilterResource()))
        ->toThrow(InvalidArgumentException::class, 'depth');
});

test('structured filter bounds the total condition count', function () {
    $conditions = array_fill(
        0,
        FilterGroup::MAX_CONDITIONS + 1,
        ['field' => 'Age', 'operator' => 'eq', 'value' => 18],
    );

    expect(fn () => FilterGroup::fromArray([
        'logic' => 'and',
        'conditions' => $conditions,
    ], phaseOneFilterResource()))->toThrow(InvalidArgumentException::class, 'conditions');
});

test('structured filter accepts exact depth and condition-count boundaries', function () {
    $conditions = array_fill(
        0,
        FilterGroup::MAX_CONDITIONS,
        ['field' => 'Age', 'operator' => 'eq', 'value' => 18],
    );

    $payload = [
        'logic' => 'and',
        'conditions' => [[
            'logic' => 'or',
            'conditions' => [[
                'logic' => 'and',
                'conditions' => $conditions,
            ]],
        ]],
    ];

    expect(FilterGroup::fromArray($payload, phaseOneFilterResource())->toArray())
        ->toBe($payload);
});
