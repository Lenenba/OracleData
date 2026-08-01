<?php

namespace App\Domain\Resource;

use InvalidArgumentException;

/**
 * Décrit comment un paramètre d'URL est résolu depuis un ancêtre.
 *
 * Exemple pour assignments→managers :
 *   new AncestorBinding(
 *       placeholder:   'personId',
 *       sourceResource: 'catalog.people',
 *       sourceField:   'personId',
 *   )
 *
 * Le binding reste une référence logique. Le planner le compilera vers le
 * nodeId exact de la branche avant l'exécution.
 */
final class AncestorBinding
{
    public function __construct(
        /** Nom du placeholder dans le template d'URL Oracle (ex: personId) */
        public readonly string $placeholder,
        /** Identifiant de la ResourceDefinition ancêtre (ex: 'catalog.people') */
        public readonly string $sourceResourceId,
        /** Nom du champ dans la réponse ancêtre dont provient la valeur */
        public readonly string $sourceField,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $this->placeholder) !== 1) {
            throw new InvalidArgumentException(
                "Ancestor binding placeholder '{$this->placeholder}' is invalid.",
            );
        }

        if (trim($this->sourceResourceId) === '') {
            throw new InvalidArgumentException('Ancestor binding source resource must not be empty.');
        }

        if (trim($this->sourceField) === '') {
            throw new InvalidArgumentException('Ancestor binding source field must not be empty.');
        }
    }

    /** @return array{placeholder: string, sourceResourceId: string, sourceField: string} */
    public function toArray(): array
    {
        return [
            'placeholder' => $this->placeholder,
            'sourceResourceId' => $this->sourceResourceId,
            'sourceField' => $this->sourceField,
        ];
    }
}
