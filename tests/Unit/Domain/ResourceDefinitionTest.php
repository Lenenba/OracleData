<?php

use App\Domain\Resource\AncestorBinding;
use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\RelationType;
use App\Domain\Resource\ResourceDefinition;
use App\Services\Workers\WorkersResourceRegistry;

// ──────────────────────────────────────────────────────────────────────────────
// RelationType
// ──────────────────────────────────────────────────────────────────────────────

test('CHILD requires ancestor context', function () {
    expect(RelationType::CHILD->requiresAncestorContext())->toBeTrue();
});

test('REFERENCE does not require ancestor context', function () {
    expect(RelationType::REFERENCE->requiresAncestorContext())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// FieldDefinition factories
// ──────────────────────────────────────────────────────────────────────────────

test('identifier factory produces isIdentifier=true', function () {
    $field = FieldDefinition::identifier('workersUniqID');
    expect($field->isIdentifier)->toBeTrue();
    expect($field->type)->toBe('integer');
    expect($field->name)->toBe('workersUniqID');
});

test('string factory sets type string', function () {
    $field = FieldDefinition::string('PersonNumber');
    expect($field->type)->toBe('string');
    expect($field->isIdentifier)->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// RelationDefinition::resolvePath — bindings multi-ancêtres
// ──────────────────────────────────────────────────────────────────────────────

test('resolvePath substitutes a single binding', function () {
    $relation = new RelationDefinition(
        id: 'test.relation',
        sourceId: 'hcm.workers',
        targetId: 'hcm.workers.workRelationships',
        type: RelationType::CHILD,
        pathTemplate: '/workers/{workersUniqID}/child/workRelationships',
        bindings: [
            new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
        ],
    );

    $context = ['hcm.workers' => ['workersUniqID' => 'ABC123']];
    $path = $relation->resolvePath($context);

    expect($path)->toBe('/workers/ABC123/child/workRelationships');
});

test('resolvePath substitutes three bindings (managers path)', function () {
    $relation = new RelationDefinition(
        id: 'managers.relation',
        sourceId: 'hcm.workers.workRelationships.assignments',
        targetId: 'hcm.workers.workRelationships.assignments.managers',
        type: RelationType::CHILD,
        pathTemplate: '/workers/{workersUniqID}/child/workRelationships/{PeriodOfServiceId}/child/assignments/{assignmentsUniqID}/child/managers',
        bindings: [
            new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID'),
            new AncestorBinding('PeriodOfServiceId', 'hcm.workers.workRelationships', 'PeriodOfServiceId'),
            new AncestorBinding('assignmentsUniqID', 'hcm.workers.workRelationships.assignments', 'assignmentsUniqID'),
        ],
    );

    $context = [
        'hcm.workers' => ['workersUniqID' => '00020000000EACED'],
        'hcm.workers.workRelationships' => ['PeriodOfServiceId' => '654321'],
        'hcm.workers.workRelationships.assignments' => ['assignmentsUniqID' => 'E10001ABC'],
    ];

    $path = $relation->resolvePath($context);

    expect($path)->toBe(
        '/workers/00020000000EACED/child/workRelationships/654321/child/assignments/E10001ABC/child/managers',
    );
});

test('resolvePath URL-encodes values with special characters', function () {
    $relation = new RelationDefinition(
        id: 'test',
        sourceId: 'a',
        targetId: 'b',
        type: RelationType::CHILD,
        pathTemplate: '/workers/{workersUniqID}/child/workRelationships',
        bindings: [new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID')],
    );

    $path = $relation->resolvePath(['hcm.workers' => ['workersUniqID' => 'abc/def']]);

    expect($path)->toBe('/workers/abc%2Fdef/child/workRelationships');
});

test('resolvePath throws when binding value is missing', function () {
    $relation = new RelationDefinition(
        id: 'test',
        sourceId: 'a',
        targetId: 'b',
        type: RelationType::CHILD,
        pathTemplate: '/workers/{workersUniqID}/child/workRelationships',
        bindings: [new AncestorBinding('workersUniqID', 'hcm.workers', 'workersUniqID')],
    );

    expect(fn () => $relation->resolvePath([]))->toThrow(RuntimeException::class);
});

test('resolvePath accepts zero as an identifier value', function () {
    $relation = new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: '/parents/{parentId}/children',
        bindings: [new AncestorBinding('parentId', 'parent', 'parentId')],
    );

    expect($relation->resolvePath(['parent' => ['parentId' => 0]]))
        ->toBe('/parents/0/children');
});

test('resolvePath rejects identifier values that cannot safely form a URL path', function (mixed $value) {
    $relation = new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: '/parents/{parentId}/children',
        bindings: [new AncestorBinding('parentId', 'parent', 'parentId')],
    );

    expect(fn () => $relation->resolvePath(['parent' => ['parentId' => $value]]))
        ->toThrow(RuntimeException::class, 'string or integer');
})->with([
    'boolean' => true,
    'float' => 1.5,
    'array' => [['nested']],
    'object' => new stdClass,
]);

test('RelationDefinition rejects a placeholder without a binding', function () {
    expect(fn () => new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: '/parents/{parentId}/children/{childId}',
        bindings: [new AncestorBinding('parentId', 'parent', 'parentId')],
    ))->toThrow(InvalidArgumentException::class, 'childId');
});

test('RelationDefinition rejects a binding without a placeholder', function () {
    expect(fn () => new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: '/parents/{parentId}/children',
        bindings: [
            new AncestorBinding('parentId', 'parent', 'parentId'),
            new AncestorBinding('unusedId', 'parent', 'unusedId'),
        ],
    ))->toThrow(InvalidArgumentException::class, 'unusedId');
});

test('RelationDefinition rejects duplicate bindings for one placeholder', function () {
    expect(fn () => new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: '/parents/{parentId}/children',
        bindings: [
            new AncestorBinding('parentId', 'parent', 'parentId'),
            new AncestorBinding('parentId', 'other-parent', 'parentId'),
        ],
    ))->toThrow(InvalidArgumentException::class, 'parentId');
});

test('RelationDefinition rejects malformed placeholders', function (string $template) {
    expect(fn () => new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: $template,
    ))->toThrow(InvalidArgumentException::class, 'malformed');
})->with([
    '/parents/{123bad}/children',
    '/parents/{bad-name}/children',
    '/parents/{unclosed/children',
    '/parents/unopened}/children',
]);

test('RelationDefinition resolves every occurrence of a bound placeholder', function () {
    $relation = new RelationDefinition(
        id: 'test',
        sourceId: 'parent',
        targetId: 'child',
        type: RelationType::CHILD,
        pathTemplate: '/parents/{parentId}/aliases/{parentId}',
        bindings: [new AncestorBinding('parentId', 'parent', 'parentId')],
    );

    expect($relation->resolvePath(['parent' => ['parentId' => 'A/B']]))
        ->toBe('/parents/A%2FB/aliases/A%2FB')
        ->not->toContain('{');
});

test('AncestorBinding stores only a logical resource and field reference', function () {
    $binding = new AncestorBinding('parentId', 'catalog.parent', 'ParentId');

    expect($binding->toArray())->toBe([
        'placeholder' => 'parentId',
        'sourceResourceId' => 'catalog.parent',
        'sourceField' => 'ParentId',
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// ResourceDefinition
// ──────────────────────────────────────────────────────────────────────────────

test('ResourceDefinition::children returns CHILD relations only', function () {
    $childRelation = new RelationDefinition(
        id: 'child', sourceId: 'parent', targetId: 'child',
        type: RelationType::CHILD, pathTemplate: '/child',
    );
    $refRelation = new RelationDefinition(
        id: 'ref', sourceId: 'parent', targetId: 'other',
        type: RelationType::REFERENCE, pathTemplate: '/ref',
    );

    $resource = new ResourceDefinition(
        id: 'parent', name: 'parent', module: 'test', label: 'Parent',
        collectionPath: '/parent', itemPath: '/parent/{id}',
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        relations: [$childRelation, $refRelation],
    );

    $children = $resource->children();

    expect($children)->toHaveCount(1);
    expect($children[0]->id)->toBe('child');
});

test('ResourceDefinition::identifierFields returns only isIdentifier=true fields', function () {
    $resource = new ResourceDefinition(
        id: 'test', name: 'test', module: 'hcm', label: 'Test',
        collectionPath: '/test', itemPath: '/test/{id}',
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        fields: [
            FieldDefinition::identifier('testId'),
            FieldDefinition::string('Name'),
        ],
    );

    expect($resource->identifierFields())->toHaveCount(1);
    expect($resource->identifierFields()[0]->name)->toBe('testId');
});

test('ResourceDefinition derives identifier names and values from FieldDefinition metadata', function () {
    $resource = new ResourceDefinition(
        id: 'test', name: 'test', module: 'hcm', label: 'Test',
        collectionPath: '/test', itemPath: '/test/{firstId}/{secondId}',
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        fields: [
            FieldDefinition::identifier('firstId'),
            FieldDefinition::string('Name'),
            FieldDefinition::identifier('secondId', 'string'),
        ],
    );

    expect($resource->identifierFieldNames())->toBe(['firstId', 'secondId'])
        ->and($resource->identifiersFromRow([
            'firstId' => 0,
            'Name' => 'Ada',
            'secondId' => 'ABC',
        ]))->toBe([
            'firstId' => 0,
            'secondId' => 'ABC',
        ])
        ->and($resource->toLegacyArray()['identifiers'])->toBe(['firstId', 'secondId']);
});

test('ResourceDefinition rejects missing and invalid identifier values from rows', function (array $row) {
    $resource = new ResourceDefinition(
        id: 'test', name: 'test', module: 'hcm', label: 'Test',
        collectionPath: '/test', itemPath: '/test/{testId}',
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        fields: [FieldDefinition::identifier('testId')],
    );

    expect(fn () => $resource->identifiersFromRow($row))
        ->toThrow(InvalidArgumentException::class, 'testId');
})->with([
    'missing' => [[]],
    'null' => [['testId' => null]],
    'empty string' => [['testId' => '']],
    'boolean' => [['testId' => false]],
    'float' => [['testId' => 1.5]],
    'array' => [['testId' => [1]]],
]);

test('ResourceDefinition rejects duplicate field names', function () {
    expect(fn () => new ResourceDefinition(
        id: 'test', name: 'test', module: 'hcm', label: 'Test',
        collectionPath: '/test', itemPath: '/test/{id}',
        apiVersion: '1', capabilities: QueryCapabilities::standard(),
        fields: [
            FieldDefinition::identifier('testId'),
            FieldDefinition::string('testId'),
        ],
    ))->toThrow(InvalidArgumentException::class, 'testId');
});

test('ResourceDefinition::toLegacyArray produces expected keys', function () {
    $resource = new ResourceDefinition(
        id: 'hcm.workers', name: 'workers', module: 'hcm', label: 'Workers',
        collectionPath: '/hcmRestApi/resources/11.13.18.05/workers',
        itemPath: '/hcmRestApi/resources/11.13.18.05/workers/{workersUniqID}',
        apiVersion: '11.13.18.05',
        capabilities: QueryCapabilities::hcm(),
        fields: [FieldDefinition::identifier('workersUniqID', 'string')],
    );

    $arr = $resource->toLegacyArray();

    expect($arr)->toHaveKey('id')
        ->toHaveKey('key')
        ->toHaveKey('module')
        ->toHaveKey('collection_path')
        ->toHaveKey('item_path')
        ->toHaveKey('fields')
        ->toHaveKey('identifiers')
        ->toHaveKey('capabilities');

    expect($arr['identifiers'])->toContain('workersUniqID');
});

test('Workers registry relation bindings reference governed identifier fields', function () {
    $resources = (new WorkersResourceRegistry)->all();
    $byId = [];

    foreach ($resources as $resource) {
        $byId[$resource->id] = $resource;

        expect($resource->toLegacyArray()['identifiers'])
            ->toBe($resource->identifierFieldNames());
    }

    foreach ($resources as $resource) {
        foreach ($resource->relations as $relation) {
            foreach ($relation->bindings as $binding) {
                expect($byId)->toHaveKey($binding->sourceResourceId);

                $field = $byId[$binding->sourceResourceId]->field($binding->sourceField);

                expect($field)->not->toBeNull()
                    ->and($field->isIdentifier)->toBeTrue();
            }
        }
    }
});
