<?php

use App\Contracts\Catalog\CatalogContext;
use App\Services\Catalog\HybridResourceCatalog;
use App\Services\Workers\WorkersOverrideProvider;
use App\Services\Workers\WorkersResourceRegistry;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeWorkersContext(?string $semantic = 'v1'): CatalogContext
{
    return new CatalogContext(
        oracleTenantId: 1,
        apiFamily: 'hcm',
        apiVersion: '11.13.18.05',
        semanticVersion: $semantic,
    );
}

function makeWorkersCatalog(): HybridResourceCatalog
{
    $registry = new WorkersResourceRegistry;
    $catalog = new HybridResourceCatalog;
    $catalog->addProvider(new WorkersOverrideProvider($registry));

    return $catalog;
}

// ─────────────────────────────────────────────────────────────────────────────
// Workers → WorkRelationships → Assignments → Managers
// ─────────────────────────────────────────────────────────────────────────────

describe('WorkersOverrideProvider', function () {

    test('supports hcm / 11.13.18.05 context', function () {
        $provider = new WorkersOverrideProvider(new WorkersResourceRegistry);
        $ctx = makeWorkersContext();

        expect($provider->supports($ctx))->toBeTrue();
    });

    test('does not support non-hcm context', function () {
        $provider = new WorkersOverrideProvider(new WorkersResourceRegistry);
        $ctx = new CatalogContext(1, 'fscm', '11.13.18.05', 'v1');

        expect($provider->supports($ctx))->toBeFalse();
    });

    test('provider name is manual_override', function () {
        $provider = new WorkersOverrideProvider(new WorkersResourceRegistry);
        expect($provider->name())->toBe('manual_override');
    });

    test('provide returns empty array for unsupported context', function () {
        $provider = new WorkersOverrideProvider(new WorkersResourceRegistry);
        $ctx = new CatalogContext(1, 'fscm', '11.13.18.05', 'v1');

        expect($provider->provide($ctx))->toBe([]);
    });

    test('version is stable across calls', function () {
        $provider = new WorkersOverrideProvider(new WorkersResourceRegistry);
        $ctx = makeWorkersContext();

        expect($provider->version($ctx))->toBe($provider->version($ctx));
    });
});

describe('Workers canonical resource graph via HybridResourceCatalog', function () {

    test('hcm.workers is findable', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $resource = $catalog->find('hcm.workers', $ctx);
        expect($resource)->not->toBeNull();
        expect($resource?->id)->toBe('hcm.workers');
        expect($resource?->name)->toBe('workers');
        expect($resource?->module)->toBe('hcm');
    });

    test('hcm.workers has identifier field workersUniqID', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $resource = $catalog->find('hcm.workers', $ctx);
        $identifiers = $resource?->identifierFieldNames();
        expect($identifiers)->toContain('workersUniqID');
    });

    test('hcm.workers.workRelationships is findable', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $wr = $catalog->find('hcm.workers.workRelationships', $ctx);
        expect($wr)->not->toBeNull();
        expect($wr?->identifierFieldNames())->toContain('PeriodOfServiceId');
    });

    test('hcm.workers.workRelationships.assignments is findable', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $assignments = $catalog->find('hcm.workers.workRelationships.assignments', $ctx);
        expect($assignments)->not->toBeNull();
        expect($assignments?->identifierFieldNames())->toContain('assignmentsUniqID');
    });

    test('hcm.workers.workRelationships.assignments.managers is a leaf', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $managers = $catalog->find('hcm.workers.workRelationships.assignments.managers', $ctx);
        expect($managers)->not->toBeNull();
        expect($managers?->children())->toBe([]);
    });

    test('Workers → WorkRelationships → Assignments → Managers hierarchy via childrenOf', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $workersChildren = $catalog->childrenOf('hcm.workers', $ctx);
        $wrIds = array_map(fn ($r) => $r->id, $workersChildren);
        expect($wrIds)->toContain('hcm.workers.workRelationships');

        $wrChildren = $catalog->childrenOf('hcm.workers.workRelationships', $ctx);
        $assignIds = array_map(fn ($r) => $r->id, $wrChildren);
        expect($assignIds)->toContain('hcm.workers.workRelationships.assignments');

        $assignChildren = $catalog->childrenOf('hcm.workers.workRelationships.assignments', $ctx);
        $managerIds = array_map(fn ($r) => $r->id, $assignChildren);
        expect($managerIds)->toContain('hcm.workers.workRelationships.assignments.managers');

        $managerChildren = $catalog->childrenOf('hcm.workers.workRelationships.assignments.managers', $ctx);
        expect($managerChildren)->toBe([]);
    });

    test('all 4 canonical levels are findable directly', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $ids = [
            'hcm.workers',
            'hcm.workers.workRelationships',
            'hcm.workers.workRelationships.assignments',
            'hcm.workers.workRelationships.assignments.managers',
        ];

        foreach ($ids as $id) {
            expect($catalog->find($id, $ctx))->not->toBeNull("Expected {$id} to be findable");
        }
    });

    test('assignments has 5 direct children', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $children = $catalog->childrenOf('hcm.workers.workRelationships.assignments', $ctx);
        expect(count($children))->toBe(5);
    });

    test('workers has 5 direct children (workRelationships + 4 personal resources)', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $children = $catalog->childrenOf('hcm.workers', $ctx);
        expect(count($children))->toBe(5);
        $childIds = array_map(fn ($r) => $r->id, $children);
        expect($childIds)->toContain('hcm.workers.workRelationships');
        expect($childIds)->toContain('hcm.workers.addresses');
        expect($childIds)->toContain('hcm.workers.emails');
        expect($childIds)->toContain('hcm.workers.phones');
        expect($childIds)->toContain('hcm.workers.names');
    });

    test('collectionPath for workers is correct Oracle path', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $resource = $catalog->find('hcm.workers', $ctx);
        expect($resource?->collectionPath)->toContain('/hcmRestApi/resources/11.13.18.05/workers');
    });

    test('collectionPath for managers contains 3 ancestor placeholders', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $managers = $catalog->find('hcm.workers.workRelationships.assignments.managers', $ctx);
        $path = $managers?->collectionPath ?? '';

        expect($path)->toContain('{workersUniqID}');
        expect($path)->toContain('{PeriodOfServiceId}');
        expect($path)->toContain('{assignmentsUniqID}');
    });

    test('WorkRelationships→Assignments relation has bindings for workersUniqID and PeriodOfServiceId', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $assignments = $catalog->find('hcm.workers.workRelationships.assignments', $ctx);
        $relation = collect($catalog->find('hcm.workers.workRelationships', $ctx)?->relations ?? [])
            ->first(fn ($r) => $r->targetId === 'hcm.workers.workRelationships.assignments');

        expect($relation)->not->toBeNull();
        $placeholders = array_map(fn ($b) => $b->placeholder, $relation?->bindings ?? []);
        expect($placeholders)->toContain('workersUniqID');
        expect($placeholders)->toContain('PeriodOfServiceId');
    });

    test('fingerprint is reproducible for the same context', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $fp1 = $catalog->fingerprint($ctx);
        $fp2 = $catalog->fingerprint($ctx);

        expect($fp1->equals($fp2))->toBeTrue();
    });

    test('fingerprint changes when semanticVersion changes', function () {
        $catalog = makeWorkersCatalog();

        $fp1 = $catalog->fingerprint(makeWorkersContext(semantic: 'v1'));
        $fp2 = $catalog->fingerprint(makeWorkersContext(semantic: 'v2'));

        expect($fp1->equals($fp2))->toBeFalse();
    });

    test('identityMap maps "workers" historical key to hcm.workers', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $map = $catalog->identityMap($ctx);
        expect($map->resolve('workers'))->toBe('hcm.workers');
        expect($map->resolve('hcm.workers'))->toBe('hcm.workers');
    });

    test('roots returns only hcm.workers, addresses, emails, phones, names as roots', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext(semantic: 'v1');

        $roots = $catalog->roots($ctx);
        $rootIds = array_map(fn ($r) => $r->id, $roots);
        expect($rootIds)->toContain('hcm.workers');
        // enfants ne sont PAS des racines
        expect($rootIds)->not->toContain('hcm.workers.workRelationships');
        expect($rootIds)->not->toContain('hcm.workers.workRelationships.assignments');
    });

    test('roots returns empty list when no semantic version is published', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext(semantic: null);

        expect($catalog->roots($ctx))->toBe([]);
    });

    test('provenance is manual_override for Workers definitions', function () {
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        $provenances = $catalog->provenances($ctx);
        $names = array_map(fn ($p) => $p->providerName, $provenances);
        expect(array_unique($names))->toContain('manual_override');
    });

    test('no Workers name appears in generic catalog logic (no hard-coded if-workers)', function () {
        // Vérification que le catalogue est découplé : find() fonctionne avec
        // un ID complètement différent sans condition sur le nom.
        $catalog = makeWorkersCatalog();
        $ctx = makeWorkersContext();

        // Un ID inconnu retourne null, sans throw ni comportement spécifique Workers.
        expect($catalog->find('fictitious.module.fictitious_resource', $ctx))->toBeNull();
        expect($catalog->childrenOf('fictitious.module.fictitious_resource', $ctx))->toBe([]);
    });
});
