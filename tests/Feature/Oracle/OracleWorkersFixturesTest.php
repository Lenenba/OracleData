<?php

use App\Services\OracleDescribeNormalizer;
use Tests\Support\OracleFixture;

const WORKERS_FIXTURE_ROOT = 'hcm/workers/11.13.18.05';

test('workers fixture manifest records provenance and unresolved tenant validation', function () {
    $manifest = OracleFixture::load(WORKERS_FIXTURE_ROOT.'/manifest.json');
    $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toMatchArray([
            'fixture_schema_version' => 1,
            'oracle_product' => 'Oracle Fusion Cloud HCM',
            'rest_resource_version' => '11.13.18.05',
            'oracle_quarterly_release' => null,
            'source_tenant_alias' => null,
            'capture_origin' => 'oracle-public-documentation-and-synthetic-contract',
            'validation_status' => 'tenant-capture-pending',
        ])
        ->and($manifest['artifacts'])->toHaveCount(8)
        ->and($manifest['known_gaps'])->not->toBeEmpty()
        ->and($encoded)->not->toContain('oraclecloud.com')
        ->not->toContain('Authorization')
        ->not->toContain('Cookie');
});

test('workers describe fixtures are accepted by the current normalizer', function () {
    $normalizer = new OracleDescribeNormalizer;
    $fixtures = [
        'workers' => ['describe/workers.json', ['PersonId', 'PersonNumber']],
        'workRelationships' => ['describe/work-relationships.json', ['PeriodOfServiceId']],
        'assignments' => ['describe/assignments.json', ['AssignmentId', 'EffectiveLatestChange']],
        'managers' => ['describe/managers.json', ['AssignmentSupervisorId', 'ManagerAssignmentId']],
    ];

    foreach ($fixtures as $resource => [$file, $requiredFields]) {
        $normalized = $normalizer->normalize(
            OracleFixture::load(WORKERS_FIXTURE_ROOT.'/'.$file),
            $resource,
        );

        expect($normalized['fields'])->toContain(...$requiredFields)
            ->and($normalized['schema_hash'])->toHaveLength(64);
    }
});

test('workers collection fixtures preserve Oracle envelopes and stable opaque links', function () {
    $files = [
        'collections/workers.json',
        'collections/work-relationships.json',
        'collections/assignments.json',
        'collections/managers.json',
    ];

    foreach ($files as $file) {
        $payload = OracleFixture::load(WORKERS_FIXTURE_ROOT.'/'.$file);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect($payload)
            ->toHaveKeys(['items', 'count', 'hasMore', 'limit', 'offset', 'links'])
            ->and($payload['items'])->toHaveCount(1)
            ->and($payload['count'])->toBe(1)
            ->and($payload['hasMore'])->toBeFalse()
            ->and($payload['limit'])->toBe(1)
            ->and($payload['offset'])->toBe(0)
            ->and($encoded)->toContain('https://oracle.invalid/')
            ->not->toContain('oraclecloud.com');
    }

    $workers = OracleFixture::load(WORKERS_FIXTURE_ROOT.'/collections/workers.json');
    $assignments = OracleFixture::load(WORKERS_FIXTURE_ROOT.'/collections/assignments.json');
    $managers = OracleFixture::load(WORKERS_FIXTURE_ROOT.'/collections/managers.json');

    expect($workers['items'][0])->not->toHaveKey('workersUniqID')
        ->and(json_encode($workers, JSON_THROW_ON_ERROR))->toContain('WRK_FAKE_HASH_001')
        ->and($assignments['items'][0])->not->toHaveKey('assignmentsUniqID')
        ->and(json_encode($assignments, JSON_THROW_ON_ERROR))->toContain('ASG_FAKE_HASH_001')
        ->and($managers['items'][0])->toHaveKeys([
            'AssignmentSupervisorId',
            'ManagerAssignmentId',
            'ManagerAssignmentNumber',
            'ManagerType',
        ])
        ->and(json_encode($managers, JSON_THROW_ON_ERROR))->toContain('MGR_FAKE_HASH_001');
});

test('oracle fixture loader rejects traversal outside its root', function () {
    expect(fn () => OracleFixture::load('../.env'))
        ->toThrow(InvalidArgumentException::class);
});
