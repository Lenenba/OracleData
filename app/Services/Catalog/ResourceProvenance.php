<?php

namespace App\Services\Catalog;

use App\Domain\Resource\ResourceDefinition;

/**
 * Représente la contribution d'un provider pour une ResourceDefinition donnée.
 *
 * Utilisée dans le merge déterministe pour tracer l'origine de chaque propriété.
 */
final class ResourceProvenance
{
    public function __construct(
        /**
         * Nom du provider source (ex: 'oracle_describe', 'semantic_catalog',
         * 'manual_override', 'legacy_fallback').
         */
        public readonly string $providerName,

        /**
         * Version du provider au moment du merge.
         */
        public readonly string $providerVersion,

        /**
         * La ResourceDefinition fournie par ce provider.
         */
        public readonly ResourceDefinition $definition,
    ) {}
}
