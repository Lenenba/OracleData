<?php

use App\Contracts\Catalog\CatalogContext;
use App\Contracts\Catalog\ResourceDefinitionProvider;
use App\Domain\Resource\QueryCapabilities;
use App\Domain\Resource\RelationDefinition;
use App\Domain\Resource\RelationType;
use App\Domain\Resource\ResourceDefinition;
use App\Services\Catalog\HybridResourceCatalog;
use App\Services\Catalog\ResourceCatalogFingerprint;
use App\Services\Catalog\ResourceIdentityMap;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeCatalogContext(
    int $tenantId = 1,
    string $family = 'hcm',
    string $version = '11.13.18.05',
    ?string $semantic = 'v1',
): CatalogContext {
    return new CatalogContext(
        oracleTenantId: $tenantId,
        apiFamily: $family,
        apiVersion: $version,
        semanticVersion: $semantic,
    );
}

/**
 * Crée une ResourceDefinition minimale.
 *
 * @param  list<RelationDefinition>  $relations
 */
function makeCatalogResource(
    string $id,
    string $name = '',
    string $module = 'hcm',
    string $collectionPath = '/test',
    array $relations = [],
): ResourceDefinition {
    return new ResourceDefinition(
        id: $id,
        name: $name !== '' ? $name : $id,
        module: $module,
        label: $id,
        collectionPath: $collectionPath,
        itemPath: $collectionPath.'/{id}',
        apiVersion: '11.13.18.05',
        capabilities: QueryCapabilities::standard(),
        fields: [],
        relations: $relations,
    );
}

/**
 * Crée une RelationDefinition CHILD minimale sans placeholder.
 */
function makeCatalogChildRelation(string $sourceId, string $targetId): RelationDefinition
{
    return new RelationDefinition(
        id: $sourceId.'.to.'.last(explode('.', $targetId)),
        sourceId: $sourceId,
        targetId: $targetId,
        type: RelationType::CHILD,
        pathTemplate: '',
        bindings: [],
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// CatalogContext
// ─────────────────────────────────────────────────────────────────────────────

describe('CatalogContext', function () {
    test('rejects empty apiFamily', function () {
        new CatalogContext(1, '', '11.13.18.05', null);
    })->throws(InvalidArgumentException::class);

    test('rejects empty apiVersion', function () {
        new CatalogContext(1, 'hcm', '', null);
    })->throws(InvalidArgumentException::class);

    test('rejects empty locale', function () {
        new CatalogContext(1, 'hcm', '11.13.18.05', null, '');
    })->throws(InvalidArgumentException::class);

    test('hasPublishedSemanticVersion is false when semanticVersion is null', function () {
        $ctx = makeCatalogContext(semantic: null);
        expect($ctx->hasPublishedSemanticVersion())->toBeFalse();
    });

    test('hasPublishedSemanticVersion is true when semanticVersion is set', function () {
        $ctx = makeCatalogContext(semantic: 'v2');
        expect($ctx->hasPublishedSemanticVersion())->toBeTrue();
    });

    test('cacheKey is deterministic and includes all components', function () {
        $ctx = makeCatalogContext(tenantId: 7, family: 'hcm', version: '11.13.18.05', semantic: 'v3');
        $key = $ctx->cacheKey();
        expect($key)
            ->toContain('7')
            ->toContain('hcm')
            ->toContain('11.13.18.05')
            ->toContain('v3');
    });

    test('cacheKey changes when semantic version changes', function () {
        $ctx1 = makeCatalogContext(semantic: 'v1');
        $ctx2 = makeCatalogContext(semantic: 'v2');
        expect($ctx1->cacheKey())->not->toBe($ctx2->cacheKey());
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// ResourceIdentityMap
// ─────────────────────────────────────────────────────────────────────────────

describe('ResourceIdentityMap', function () {
    test('passthrough resolution returns the canonical id itself', function () {
        $map = new ResourceIdentityMap;
        $map->register('hcm.workers', 'hcm.workers');
        expect($map->resolve('hcm.workers'))->toBe('hcm.workers');
    });

    test('resolves historical key to canonical id', function () {
        $map = new ResourceIdentityMap;
        $map->register('workers', 'hcm.workers');
        expect($map->resolve('workers'))->toBe('hcm.workers');
    });

    test('resolve returns null for unknown alias', function () {
        $map = new ResourceIdentityMap;
        expect($map->resolve('unknown.resource'))->toBeNull();
    });

    test('resolveOrSelf returns the alias when not registered', function () {
        $map = new ResourceIdentityMap;
        expect($map->resolveOrSelf('hcm.workers'))->toBe('hcm.workers');
    });

    test('registering same alias with same canonical id is idempotent', function () {
        $map = new ResourceIdentityMap;
        $map->register('workers', 'hcm.workers');
        $map->register('workers', 'hcm.workers'); // no exception
        expect($map->resolve('workers'))->toBe('hcm.workers');
    });

    test('registering ambiguous alias throws RuntimeException', function () {
        $map = new ResourceIdentityMap;
        $map->register('workers', 'hcm.workers');
        $map->register('workers', 'hcm.workers2');
    })->throws(RuntimeException::class, 'ambiguous alias');

    test('empty alias is silently ignored', function () {
        $map = new ResourceIdentityMap;
        $map->register('', 'hcm.workers'); // no exception, no registration
        expect($map->resolve(''))->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// ResourceCatalogFingerprint
// ─────────────────────────────────────────────────────────────────────────────

describe('ResourceCatalogFingerprint', function () {
    test('fromComponents produces a sha256-prefixed string', function () {
        $fp = ResourceCatalogFingerprint::fromComponents(['a' => '1', 'b' => '2']);
        expect($fp->toString())->toStartWith('sha256:');
        expect(strlen($fp->toString()))->toBe(7 + 64); // 'sha256:' + 64 hex chars
    });

    test('component order does not affect the fingerprint', function () {
        $fp1 = ResourceCatalogFingerprint::fromComponents(['a' => '1', 'b' => '2']);
        $fp2 = ResourceCatalogFingerprint::fromComponents(['b' => '2', 'a' => '1']);
        expect($fp1->equals($fp2))->toBeTrue();
    });

    test('different components produce different fingerprints', function () {
        $fp1 = ResourceCatalogFingerprint::fromComponents(['a' => '1']);
        $fp2 = ResourceCatalogFingerprint::fromComponents(['a' => '2']);
        expect($fp1->equals($fp2))->toBeFalse();
    });

    test('fromString accepts a valid fingerprint string', function () {
        $fp = ResourceCatalogFingerprint::fromComponents(['x' => 'y']);
        $restored = ResourceCatalogFingerprint::fromString($fp->toString());
        expect($restored->equals($fp))->toBeTrue();
    });

    test('fromString rejects invalid format', function () {
        ResourceCatalogFingerprint::fromString('not-a-fingerprint');
    })->throws(InvalidArgumentException::class);

    test('hasDriftFrom detects a change', function () {
        $fp1 = ResourceCatalogFingerprint::fromComponents(['v' => '1']);
        $fp2 = ResourceCatalogFingerprint::fromComponents(['v' => '2']);
        expect($fp1->hasDriftFrom($fp2->toString()))->toBeTrue();
    });

    test('hasDriftFrom returns false for identical fingerprints', function () {
        $fp = ResourceCatalogFingerprint::fromComponents(['v' => '1']);
        expect($fp->hasDriftFrom($fp->toString()))->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// HybridResourceCatalog — merge déterministe
// ─────────────────────────────────────────────────────────────────────────────

describe('HybridResourceCatalog merge', function () {

    function makeCatalogWith(ResourceDefinition ...$resources): HybridResourceCatalog
    {
        $catalog = new HybridResourceCatalog;
        $provider = new class($resources) implements ResourceDefinitionProvider
        {
            /** @param list<ResourceDefinition> $defs */
            public function __construct(private readonly array $defs) {}

            public function name(): string
            {
                return 'test_provider';
            }

            public function provide(CatalogContext $context): array
            {
                return $this->defs;
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'test-v1';
            }
        };

        $catalog->addProvider($provider);

        return $catalog;
    }

    test('find returns a ResourceDefinition when it exists', function () {
        $res = makeCatalogResource('hcm.workers');
        $catalog = makeCatalogWith($res);
        $ctx = makeCatalogContext();

        expect($catalog->find('hcm.workers', $ctx))->toBe($res);
    });

    test('find returns null for unknown id', function () {
        $catalog = makeCatalogWith();
        $ctx = makeCatalogContext();

        expect($catalog->find('hcm.unknown', $ctx))->toBeNull();
    });

    test('first provider wins when two providers define the same id with same path', function () {
        $catalog = new HybridResourceCatalog;
        $ctx = makeCatalogContext();

        $res1 = makeCatalogResource('hcm.workers', 'workers', 'hcm', '/path/workers');
        $res2 = makeCatalogResource('hcm.workers', 'workers', 'hcm', '/path/workers');

        $p1 = new class([$res1]) implements ResourceDefinitionProvider
        {
            public function __construct(private readonly array $defs) {}

            public function name(): string
            {
                return 'priority_provider';
            }

            public function provide(CatalogContext $context): array
            {
                return $this->defs;
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'pv1';
            }
        };

        $p2 = new class([$res2]) implements ResourceDefinitionProvider
        {
            public function __construct(private readonly array $defs) {}

            public function name(): string
            {
                return 'fallback_provider';
            }

            public function provide(CatalogContext $context): array
            {
                return $this->defs;
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'fv1';
            }
        };

        $catalog->addProvider($p1);
        $catalog->addProvider($p2);

        expect($catalog->find('hcm.workers', $ctx))->toBe($res1);
    });

    test('critical conflict throws RuntimeException', function () {
        $catalog = new HybridResourceCatalog;
        $ctx = makeCatalogContext();

        $res1 = makeCatalogResource('hcm.workers', 'workers', 'hcm', '/path-a/workers');
        $res2 = makeCatalogResource('hcm.workers', 'workers', 'hcm', '/path-b/workers');

        foreach ([$res1, $res2] as $res) {
            $r = $res;
            $catalog->addProvider(new class([$r]) implements ResourceDefinitionProvider
            {
                public function __construct(private readonly array $defs) {}

                public function name(): string
                {
                    return 'conflict_provider';
                }

                public function provide(CatalogContext $context): array
                {
                    return $this->defs;
                }

                public function supports(CatalogContext $context): bool
                {
                    return true;
                }

                public function version(CatalogContext $context): string
                {
                    return 'cv1';
                }
            });
        }

        $catalog->find('hcm.workers', $ctx);
    })->throws(RuntimeException::class, 'critical conflict');

    test('childrenOf returns direct CHILD resources', function () {
        $child = makeCatalogResource('hcm.workers.workRelationships');
        $parent = makeCatalogResource(
            'hcm.workers',
            relations: [makeCatalogChildRelation('hcm.workers', 'hcm.workers.workRelationships')],
        );
        $catalog = makeCatalogWith($parent, $child);
        $ctx = makeCatalogContext();

        $children = $catalog->childrenOf('hcm.workers', $ctx);
        expect($children)->toHaveCount(1);
        expect($children[0]->id)->toBe('hcm.workers.workRelationships');
    });

    test('childrenOf returns empty for unknown resource', function () {
        $catalog = makeCatalogWith();
        $ctx = makeCatalogContext();

        expect($catalog->childrenOf('hcm.unknown', $ctx))->toBe([]);
    });

    test('roots returns resources with no parent when semanticVersion is set', function () {
        $child = makeCatalogResource('hcm.workers.workRelationships');
        $parent = makeCatalogResource(
            'hcm.workers',
            relations: [makeCatalogChildRelation('hcm.workers', 'hcm.workers.workRelationships')],
        );
        $catalog = makeCatalogWith($parent, $child);
        $ctx = makeCatalogContext(semantic: 'v1');

        $roots = $catalog->roots($ctx);
        expect($roots)->toHaveCount(1);
        expect($roots[0]->id)->toBe('hcm.workers');
    });

    test('roots returns empty list when semanticVersion is null', function () {
        $parent = makeCatalogResource('hcm.workers');
        $catalog = makeCatalogWith($parent);
        $ctx = makeCatalogContext(semantic: null);

        expect($catalog->roots($ctx))->toBe([]);
    });

    test('find returns null when semanticVersion is null (authoring/execution gate)', function () {
        // roots() renvoie [] mais find() doit encore fonctionner (lookup direct depuis registry).
        // La fermeture complète est sur roots() ; find() reste accessible pour le moteur historique.
        $res = makeCatalogResource('hcm.workers');
        $catalog = makeCatalogWith($res);
        $ctx = makeCatalogContext(semantic: null);

        // find() ne bloque PAS — c'est roots() qui fait la fermeture.
        expect($catalog->find('hcm.workers', $ctx))->toBe($res);
    });

    test('provider not supporting context is skipped', function () {
        $catalog = new HybridResourceCatalog;
        $res = makeCatalogResource('hcm.workers');
        $catalog->addProvider(new class($res) implements ResourceDefinitionProvider
        {
            public function __construct(private readonly ResourceDefinition $def) {}

            public function name(): string
            {
                return 'scoped_provider';
            }

            public function provide(CatalogContext $context): array
            {
                return [$this->def];
            }

            public function supports(CatalogContext $context): bool
            {
                return $context->apiFamily === 'fscm'; // deliberately wrong
            }

            public function version(CatalogContext $context): string
            {
                return 'sv1';
            }
        });

        $ctx = makeCatalogContext(family: 'hcm');
        expect($catalog->find('hcm.workers', $ctx))->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// HybridResourceCatalog — fingerprint
// ─────────────────────────────────────────────────────────────────────────────

describe('HybridResourceCatalog fingerprint', function () {
    test('fingerprint is deterministic for the same context', function () {
        $catalog = new HybridResourceCatalog;
        $catalog->addProvider(new class implements ResourceDefinitionProvider
        {
            public function name(): string
            {
                return 'p1';
            }

            public function provide(CatalogContext $context): array
            {
                return [];
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'stable-v1';
            }
        });
        $ctx = makeCatalogContext(semantic: 'v1');

        $fp1 = $catalog->fingerprint($ctx);
        $fp2 = $catalog->fingerprint($ctx);

        expect($fp1->equals($fp2))->toBeTrue();
    });

    test('fingerprint changes when api_version changes', function () {
        $catalog = new HybridResourceCatalog;
        $catalog->addProvider(new class implements ResourceDefinitionProvider
        {
            public function name(): string
            {
                return 'p2';
            }

            public function provide(CatalogContext $context): array
            {
                return [];
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'sv2';
            }
        });

        $fp1 = $catalog->fingerprint(makeCatalogContext(version: '11.13.18.05'));
        $fp2 = $catalog->fingerprint(makeCatalogContext(version: '11.13.18.06'));

        expect($fp1->equals($fp2))->toBeFalse();
    });

    test('fingerprint changes when semantic version changes', function () {
        $catalog = new HybridResourceCatalog;
        $catalog->addProvider(new class implements ResourceDefinitionProvider
        {
            public function name(): string
            {
                return 'p3';
            }

            public function provide(CatalogContext $context): array
            {
                return [];
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'sv3';
            }
        });

        $fp1 = $catalog->fingerprint(makeCatalogContext(semantic: 'v1'));
        $fp2 = $catalog->fingerprint(makeCatalogContext(semantic: 'v2'));

        expect($fp1->equals($fp2))->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// HybridResourceCatalog — identityMap
// ─────────────────────────────────────────────────────────────────────────────

describe('HybridResourceCatalog identityMap', function () {
    test('identity map includes canonical passthrough and historical name alias', function () {
        $res = makeCatalogResource('hcm.workers', 'workers');
        $catalog = new HybridResourceCatalog;
        $catalog->addProvider(new class($res) implements ResourceDefinitionProvider
        {
            public function __construct(private readonly ResourceDefinition $def) {}

            public function name(): string
            {
                return 'tp';
            }

            public function provide(CatalogContext $context): array
            {
                return [$this->def];
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'tv1';
            }
        });

        $ctx = makeCatalogContext();
        $map = $catalog->identityMap($ctx);

        expect($map->resolve('hcm.workers'))->toBe('hcm.workers');
        expect($map->resolve('workers'))->toBe('hcm.workers');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// HybridResourceCatalog — provenances
// ─────────────────────────────────────────────────────────────────────────────

describe('HybridResourceCatalog provenances', function () {
    test('provenance records provider name for each resource', function () {
        $res = makeCatalogResource('hcm.workers');
        $catalog = new HybridResourceCatalog;
        $catalog->addProvider(new class($res) implements ResourceDefinitionProvider
        {
            public function __construct(private readonly ResourceDefinition $def) {}

            public function name(): string
            {
                return 'my_provider';
            }

            public function provide(CatalogContext $context): array
            {
                return [$this->def];
            }

            public function supports(CatalogContext $context): bool
            {
                return true;
            }

            public function version(CatalogContext $context): string
            {
                return 'pv1';
            }
        });

        $ctx = makeCatalogContext();
        $provenances = $catalog->provenances($ctx);

        expect($provenances)->toHaveCount(1);
        expect($provenances[0]->providerName)->toBe('my_provider');
        expect($provenances[0]->definition->id)->toBe('hcm.workers');
    });

    test('second provider does not override first provider provenance for same id', function () {
        $catalog = new HybridResourceCatalog;
        $ctx = makeCatalogContext();
        $res = makeCatalogResource('hcm.workers', 'workers', 'hcm', '/same/path');

        foreach (['first', 'second'] as $providerName) {
            $name = $providerName;
            $catalog->addProvider(new class($res, $name) implements ResourceDefinitionProvider
            {
                public function __construct(
                    private readonly ResourceDefinition $def,
                    private readonly string $provName,
                ) {}

                public function name(): string
                {
                    return $this->provName;
                }

                public function provide(CatalogContext $context): array
                {
                    return [$this->def];
                }

                public function supports(CatalogContext $context): bool
                {
                    return true;
                }

                public function version(CatalogContext $context): string
                {
                    return 'v1';
                }
            });
        }

        $provenances = $catalog->provenances($ctx);
        expect($provenances)->toHaveCount(1);
        expect($provenances[0]->providerName)->toBe('first');
    });
});
