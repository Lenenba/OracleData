<?php

namespace App\Services\Catalog;

/**
 * Empreinte immuable et déterministe du Resource Graph hybride.
 *
 * L'empreinte est calculée à partir de :
 *   - la version du catalogue sémantique publié
 *   - les versions des snapshots Oracle /describe (par tenant + version API + ressource)
 *   - la version de chaque provider d'overrides
 *   - la version de l'adaptateur legacy
 *
 * Elle ne change que si un composant versionné change.
 * Elle est identique pour les mêmes entrées, quel que soit l'ordre d'appel.
 *
 * Utilisation :
 *   - stockée avec un QueryGraph pour détecter une dérive de catalogue
 *   - comparée lors de la revalidation (authoring et exécution)
 *   - jamais utilisée pour ré-autoriser une ressource retirée de la liste blanche
 */
final class ResourceCatalogFingerprint
{
    private const ALGO = 'sha256';

    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Calcule l'empreinte depuis un tableau de composants versionnés.
     *
     * @param  array<string, string>  $components  [composant → version]
     *                                             L'ordre ne compte pas — les composants sont triés alphabétiquement.
     */
    public static function fromComponents(array $components): self
    {
        ksort($components);
        $canonical = json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $hash = hash(self::ALGO, $canonical);

        return new self(self::ALGO.':'.$hash);
    }

    /**
     * Reconstitue depuis une chaîne enregistrée (ex: 'sha256:abc123...').
     *
     * @throws \InvalidArgumentException si le format est invalide.
     */
    public static function fromString(string $value): self
    {
        if (! str_starts_with($value, self::ALGO.':') || strlen($value) !== strlen(self::ALGO.':') + 64) {
            throw new \InvalidArgumentException(
                "Invalid ResourceCatalogFingerprint format: '{$value}'.",
            );
        }

        return new self($value);
    }

    /**
     * Chaîne préfixée : 'sha256:<hex64>'.
     * Stable et appropriée pour la persistance dans queries.query_graph.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Indique si deux empreintes sont identiques.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Indique si cette empreinte diffère de la valeur persistée.
     * Utile pour détecter une dérive de catalogue entre la sauvegarde et l'exécution.
     */
    public function hasDriftFrom(string $storedFingerprint): bool
    {
        return $this->value !== $storedFingerprint;
    }
}
