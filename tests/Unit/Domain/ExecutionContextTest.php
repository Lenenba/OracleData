<?php

use App\Domain\Query\ExecutionContext;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\ResourceDefinition;

function phaseOneExecutionResource(string $id, string $identifier): ResourceDefinition
{
    return new ResourceDefinition(
        id: $id,
        name: str_replace('.', '-', $id),
        module: 'test',
        label: $id,
        collectionPath: '/resources',
        itemPath: '/resources/{'.$identifier.'}',
        apiVersion: '1',
        capabilities: QueryCapabilities::standard(),
        fields: [
            FieldDefinition::identifier($identifier),
            FieldDefinition::string('DisplayName'),
        ],
    );
}

test('execution contexts form immutable frame chains per branch', function () {
    $people = phaseOneExecutionResource('catalog.people', 'PersonId');
    $assignments = phaseOneExecutionResource('catalog.assignments', 'AssignmentId');

    $root = (new ExecutionContext)->push(
        nodeId: 'root-node',
        resource: $people,
        row: ['PersonId' => 10, 'DisplayName' => 'Root'],
    );

    $leftBranch = $root->push(
        nodeId: 'left-assignment',
        resource: $assignments,
        row: ['AssignmentId' => 101, 'Name' => 'Left'],
    );

    $rightBranch = $root->push(
        nodeId: 'right-assignment',
        resource: $assignments,
        row: ['AssignmentId' => 202, 'Name' => 'Right'],
    );

    expect($root->get('catalog.assignments', 'AssignmentId'))->toBeNull()
        ->and($leftBranch->get('catalog.assignments', 'AssignmentId'))->toBe(101)
        ->and($rightBranch->get('catalog.assignments', 'AssignmentId'))->toBe(202)
        ->and($leftBranch->get('catalog.people', 'PersonId'))->toBe(10)
        ->and($rightBranch->get('catalog.people', 'PersonId'))->toBe(10);
});

test('execution context keeps node identity when a resource repeats in one branch', function () {
    $people = phaseOneExecutionResource('catalog.people', 'PersonId');

    $root = (new ExecutionContext)->push(
        nodeId: 'root-occurrence',
        resource: $people,
        row: ['PersonId' => 10],
    );

    $repeated = $root->push(
        nodeId: 'nested-occurrence',
        resource: $people,
        row: ['PersonId' => 20],
    );

    expect($repeated->get('catalog.people', 'PersonId'))->toBe(20)
        ->and($repeated->getForNode('root-occurrence', 'PersonId'))->toBe(10)
        ->and($repeated->getForNode('nested-occurrence', 'PersonId'))->toBe(20)
        ->and(array_map(
            fn ($frame): string => $frame->nodeId,
            $repeated->frames(),
        ))->toBe(['root-occurrence', 'nested-occurrence']);
});

test('execution frame exposes its row separately from governed identifiers', function () {
    $people = phaseOneExecutionResource('catalog.people', 'PersonId');

    $context = (new ExecutionContext)->push(
        nodeId: 'person-node',
        resource: $people,
        row: ['PersonId' => 10, 'DisplayName' => 'Ada'],
    );

    $frame = $context->currentFrame();

    expect($frame)->not->toBeNull()
        ->and($frame->row)->toBe(['PersonId' => 10, 'DisplayName' => 'Ada'])
        ->and($frame->identifiers)->toBe(['PersonId' => 10]);
});

test('execution frames derive identifiers only from the resource definition', function () {
    $people = phaseOneExecutionResource('catalog.people', 'PersonId');

    $context = (new ExecutionContext)->push(
        nodeId: 'person-node',
        resource: $people,
        row: ['PersonId' => 10, 'DisplayName' => 'Ada'],
    );

    expect($context->currentFrame()?->identifiers)
        ->toBe(['PersonId' => 10])
        ->not->toHaveKey('DisplayName');
});

test('execution frame creation fails when a governed identifier is absent', function () {
    $people = phaseOneExecutionResource('catalog.people', 'PersonId');

    expect(fn () => (new ExecutionContext)->push(
        nodeId: 'person-node',
        resource: $people,
        row: ['DisplayName' => 'Ada'],
    ))->toThrow(InvalidArgumentException::class, 'PersonId');
});

test('nearest repeated resource occurrence shadows stale outer and legacy values', function () {
    $people = phaseOneExecutionResource('catalog.people', 'PersonId');
    $legacy = (new ExecutionContext)
        ->with('catalog.people', 'LegacyId', 999)
        ->with('catalog.people', 'PersonId', 888);

    $outer = $legacy->push(
        nodeId: 'outer-person',
        resource: $people,
        row: ['PersonId' => 10],
    );

    $inner = $outer->push(
        nodeId: 'inner-person',
        resource: $people,
        row: ['PersonId' => 20],
    );

    expect($inner->get('catalog.people', 'PersonId'))->toBe(20)
        ->and($inner->get('catalog.people', 'LegacyId'))->toBeNull()
        ->and($inner->has('catalog.people', 'LegacyId'))->toBeFalse()
        ->and($inner->toBindings()['catalog.people'])->toBe(['PersonId' => 20])
        ->and($outer->toBindings()['catalog.people'])->toBe(['PersonId' => 10])
        ->and($legacy->toBindings()['catalog.people'])->toBe([
            'LegacyId' => 999,
            'PersonId' => 888,
        ]);
});
