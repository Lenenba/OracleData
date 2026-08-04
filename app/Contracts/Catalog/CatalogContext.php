<?php

namespace App\Contracts\Catalog;

use InvalidArgumentException;

/**
 * Contexte d'un appel au catalogue canonique.
 *
 * Construit exclusivement côté serveur à partir d'une connexion Oracle
 * accessible à l'utilisateur courant. Le navigateur ne fournit jamais
 * un tenant, une version ou une empreinte comme autorité.
 *
 * Éléments immuables utilisés lors du merge et de la validation :
 *  - oracleTenantId  : identifiant interne du tenant Oracle de l'utilisateur
 *  - apiFamily       : famille d'API (ex: 'hcm', 'fscm')
 *  - apiVersion      : version de l'API Oracle (ex: '11.13.18.05')
 *  - semanticVersion : version publiée du catalogue sémantique (ou null → échec fermé pour le graphe)
 *  - locale          : locale pour les labels/descriptions ('fr', 'en', 'es')
 */
final class CatalogContext
{
    public function __construct(
        /**
         * ID interne du tenant Oracle appartenant à l'utilisateur.
         * Correspond à oracle_tenants.id.
         */
        public readonly int $oracleTenantId,

        /**
         * Famille d'API Oracle (ex: 'hcm', 'fscm').
         */
        public readonly string $apiFamily,

        /**
         * Version de l'API Oracle (ex: '11.13.18.05').
         */
        public readonly string $apiVersion,

        /**
         * Version publiée du catalogue sémantique.
         * Null si aucune version n'est encore publiée pour cette famille.
         *
         * Lorsque null, l'authoring et l'exécution de graphe doivent
         * échouer de manière fermée. Le moteur historique est inchangé.
         */
        public readonly ?string $semanticVersion,

        /**
         * Locale pour les labels et descriptions.
         * N'affecte pas les IDs, noms techniques ou chemins.
         */
        public readonly string $locale = 'fr',
    ) {
        if (trim($this->apiFamily) === '') {
            throw new InvalidArgumentException('CatalogContext apiFamily must not be empty.');
        }

        if (trim($this->apiVersion) === '') {
            throw new InvalidArgumentException('CatalogContext apiVersion must not be empty.');
        }

        if (trim($this->locale) === '') {
            throw new InvalidArgumentException('CatalogContext locale must not be empty.');
        }
    }

    /**
     * Indique si une version sémantique publiée est disponible.
     *
     * Sans version publiée, l'authoring et l'exécution de graphe sont bloqués.
     * Le moteur historique conserve son comportement quel que soit l'état.
     */
    public function hasPublishedSemanticVersion(): bool
    {
        return $this->semanticVersion !== null;
    }

    /** Clé de cache déterministe pour ce contexte. */
    public function cacheKey(): string
    {
        return implode(':', [
            'catalog',
            $this->oracleTenantId,
            $this->apiFamily,
            $this->apiVersion,
            $this->semanticVersion ?? 'none',
            $this->locale,
        ]);
    }
}
