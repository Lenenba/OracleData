<?php

namespace App\Services\Catalog;

/**
 * Table de correspondance d'identités stables entre les trois espaces de noms.
 *
 * Résout sans ambiguïté :
 *   clé historique  →  ID canonique  (ex: 'workers'        → 'hcm.workers')
 *   ID sémantique   →  ID canonique  (ex: 'hcm_workers'    → 'hcm.workers')
 *   ID canonique    →  ID canonique  (passthrough)
 *
 * Un alias ambigu (plusieurs IDs canoniques pour la même clé) est refusé.
 * Cela garantit qu'aucun consommateur ne résout silencieusement vers la mauvaise ressource.
 */
final class ResourceIdentityMap
{
    /** @var array<string, string>  [alias → canonicalId] */
    private array $map = [];

    /**
     * Enregistre un alias.
     *
     * @throws \RuntimeException si l'alias est déjà mappé vers un ID différent.
     */
    public function register(string $alias, string $canonicalId): void
    {
        if ($alias === '') {
            return;
        }

        if (isset($this->map[$alias]) && $this->map[$alias] !== $canonicalId) {
            throw new \RuntimeException(
                "ResourceIdentityMap: ambiguous alias '{$alias}' maps to both '{$this->map[$alias]}' and '{$canonicalId}'.",
            );
        }

        $this->map[$alias] = $canonicalId;
    }

    /**
     * Résout un alias vers l'ID canonique, ou null si inconnu.
     */
    public function resolve(string $alias): ?string
    {
        return $this->map[$alias] ?? null;
    }

    /**
     * Résout un alias ou retourne l'alias lui-même si c'est déjà un ID canonique connu.
     * Utile lors du lookup : `$map->resolveOrSelf('hcm.workers')` reste 'hcm.workers'.
     */
    public function resolveOrSelf(string $alias): string
    {
        return $this->map[$alias] ?? $alias;
    }

    /**
     * Retourne tous les alias enregistrés.
     *
     * @return array<string, string> [alias → canonicalId]
     */
    public function all(): array
    {
        return $this->map;
    }
}
