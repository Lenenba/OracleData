<?php

namespace App\Services\Workers;

use App\Contracts\Catalog\CatalogContext;
use App\Contracts\Catalog\ResourceDefinitionProvider;
use App\Domain\Resource\ResourceDefinition;

/**
 * Provider d'overrides techniques pour le module HCM Workers.
 *
 * Ce provider apporte uniquement ce que /describe ne permet pas de déterminer
 * de façon fiable : chemins Oracle, identifiants de URL, bindings et capacités.
 *
 * Il ne constitue PAS un catalogue complet concurrent.
 * Le catalogue sémantique publié reste la frontière d'autorisation.
 *
 * Familles et version couvertes : hcm / 11.13.18.05
 *
 * Hiérarchie déclarée :
 *   hcm.workers                                          (depth 1)
 *   └── hcm.workers.workRelationships                    (depth 2)
 *       └── hcm.workers.workRelationships.assignments    (depth 3)
 *           ├── hcm.workers.workRelationships.assignments.managers         (depth 4)
 *           ├── hcm.workers.workRelationships.assignments.allReports       (depth 4)
 *           ├── hcm.workers.workRelationships.assignments.gradeSteps       (depth 4)
 *           ├── hcm.workers.workRelationships.assignments.representatives  (depth 4)
 *           └── hcm.workers.workRelationships.assignments.workMeasures     (depth 4)
 *   ├── hcm.workers.addresses                            (depth 2)
 *   ├── hcm.workers.emails                               (depth 2)
 *   ├── hcm.workers.phones                               (depth 2)
 *   └── hcm.workers.names                                (depth 2)
 */
final class WorkersOverrideProvider implements ResourceDefinitionProvider
{
    /** Famille Oracle couverte par ce provider. */
    private const FAMILY = 'hcm';

    /** Version de ressource Oracle couverte. */
    private const API_VERSION = '11.13.18.05';

    /**
     * Version interne du provider.
     * Incrémenter lorsqu'une définition change (chemin, identifiant, binding).
     * Utilisée dans l'empreinte du catalogue hybride.
     */
    private const PROVIDER_VERSION = 'workers-override-v1';

    private readonly WorkersResourceRegistry $registry;

    public function __construct(WorkersResourceRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function name(): string
    {
        return 'manual_override';
    }

    /**
     * Ce provider ne couvre que la famille hcm, version 11.13.18.05.
     * Tout autre contexte doit utiliser un autre provider.
     */
    public function supports(CatalogContext $context): bool
    {
        return $context->apiFamily === self::FAMILY
            && $context->apiVersion === self::API_VERSION;
    }

    /**
     * Fournit les ResourceDefinitions Workers complètes pour ce contexte.
     *
     * @return list<ResourceDefinition>
     */
    public function provide(CatalogContext $context): array
    {
        if (! $this->supports($context)) {
            return [];
        }

        return $this->registry->all();
    }

    /**
     * Version déterministe de la contribution de ce provider.
     * Identique pour toute instance — les overrides sont fixes dans ce provider.
     */
    public function version(CatalogContext $context): string
    {
        return self::PROVIDER_VERSION;
    }
}
