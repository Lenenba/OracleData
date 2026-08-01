<?php

namespace App\Domain\Query;

use App\Domain\Resource\ResourceDefinition;

/**
 * Transporte les identifiants ancêtres pendant la traversée du QueryGraph.
 *
 * Le moteur d'exécution alimente ce contexte au fur et à mesure qu'il
 * récupère les données Oracle. Chaque RelationDefinition::resolvePath()
 * consulte ce contexte pour substituer les placeholders d'URL.
 *
 * La structure primaire est une chaîne immuable de frames propre à une
 * branche. Un même resourceId peut ainsi apparaître plusieurs fois sans
 * écraser une occurrence ancêtre ou une branche sœur.
 *
 * Exemple :
 *   $branch = $ctx->push('node-1', $peopleResource, $row);
 *   $childBranch = $branch->push('node-2', $assignmentsResource, $row);
 */
final class ExecutionContext
{
    private ?ExecutionFrame $currentFrame = null;

    /**
     * Compatibilité temporaire avec l'API du prototype. Le planner et le futur
     * exécuteur doivent utiliser push() afin de conserver nodeId et la branche.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $legacyValues = [];

    public function set(string $resourceId, string $field, mixed $value): void
    {
        $this->legacyValues[$resourceId][$field] = $value;
    }

    /** Retourne la valeur d'un champ pour une ressource donnée, ou null. */
    public function get(string $resourceId, string $field): mixed
    {
        $frame = $this->currentFrame;

        while ($frame !== null) {
            if ($frame->resourceId === $resourceId) {
                return $frame->identifiers[$field] ?? null;
            }

            $frame = $frame->parent;
        }

        return $this->legacyValues[$resourceId][$field] ?? null;
    }

    /** Retourne true si la valeur est disponible. */
    public function has(string $resourceId, string $field): bool
    {
        $frame = $this->currentFrame;

        while ($frame !== null) {
            if ($frame->resourceId === $resourceId) {
                return array_key_exists($field, $frame->identifiers);
            }

            $frame = $frame->parent;
        }

        return array_key_exists($field, $this->legacyValues[$resourceId] ?? []);
    }

    /** Retourne une valeur gouvernée pour une occurrence de nœud exacte. */
    public function getForNode(string $nodeId, string $field): mixed
    {
        $frame = $this->currentFrame;

        while ($frame !== null) {
            if ($frame->nodeId === $nodeId) {
                return $frame->identifiers[$field] ?? null;
            }

            $frame = $frame->parent;
        }

        return null;
    }

    /**
     * Retourne le contexte sous forme de tableau compatible avec
     * RelationDefinition::resolvePath().
     *
     * @return array<string, array<string, mixed>>
     */
    public function toBindings(): array
    {
        $bindings = $this->legacyValues;

        foreach ($this->frames() as $frame) {
            $bindings[$frame->resourceId] = $frame->identifiers;
        }

        return $bindings;
    }

    /** Crée une copie enrichie du contexte sans muter l'instance originale. */
    public function with(string $resourceId, string $field, mixed $value): self
    {
        $clone = clone $this;
        $clone->legacyValues[$resourceId][$field] = $value;

        return $clone;
    }

    /**
     * Alimente le contexte depuis un row Oracle (tableau clé-valeur).
     * Seuls les champs identifiants de la ressource sont extraits.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $identifierFields
     */
    public function populateFromRow(string $resourceId, array $row, array $identifierFields): self
    {
        $clone = clone $this;
        foreach ($identifierFields as $field) {
            if (array_key_exists($field, $row)) {
                $clone->legacyValues[$resourceId][$field] = $row[$field];
            }
        }

        return $clone;
    }

    /**
     * Ajoute une occurrence à la branche et retourne un nouveau contexte.
     *
     * @param  array<string, mixed>  $row
     */
    public function push(
        string $nodeId,
        ResourceDefinition $resource,
        array $row,
    ): self {
        $clone = clone $this;
        $clone->currentFrame = new ExecutionFrame(
            nodeId: $nodeId,
            resource: $resource,
            row: $row,
            parent: $this->currentFrame,
        );

        return $clone;
    }

    public function currentFrame(): ?ExecutionFrame
    {
        return $this->currentFrame;
    }

    /** @return list<ExecutionFrame> */
    public function frames(): array
    {
        $frames = [];
        $frame = $this->currentFrame;

        while ($frame !== null) {
            $frames[] = $frame;
            $frame = $frame->parent;
        }

        return array_reverse($frames);
    }
}
