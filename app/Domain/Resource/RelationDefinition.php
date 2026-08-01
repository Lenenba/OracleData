<?php

namespace App\Domain\Resource;

use InvalidArgumentException;
use RuntimeException;

/**
 * Décrit une relation entre deux ResourceDefinitions.
 *
 * Pour une relation CHILD Oracle, le path template contient des placeholders
 * qui sont résolus depuis le ExecutionContext (valeurs des ancêtres).
 *
 * Exemple people → assignments :
 *   id:              'catalog.people.assignments'
 *   sourceId:        'catalog.people'
 *   targetId:        'catalog.people.assignments'
 *   type:            RelationType::CHILD
 *   pathTemplate:    '/people/{personId}/child/assignments'
 *   bindings:        [AncestorBinding(placeholder='personId', source='catalog.people', field='personId')]
 *
 * Exemple assignments → supervisors :
 *   pathTemplate:    '/people/{personId}/child/assignments/{assignmentId}/child/supervisors'
 *   bindings:        [
 *                      AncestorBinding('personId',     'catalog.people',             'personId'),
 *                      AncestorBinding('assignmentId', 'catalog.people.assignments', 'assignmentId'),
 *                    ]
 */
final class RelationDefinition
{
    /**
     * @param  list<AncestorBinding>  $bindings
     */
    public function __construct(
        /** Identifiant unique de cette relation (ex: 'catalog.people.assignments') */
        public readonly string $id,
        /** Resource source (parent) */
        public readonly string $sourceId,
        /** Resource cible (enfant) */
        public readonly string $targetId,
        /** Type de relation */
        public readonly RelationType $type,
        /**
         * Template d'URL Oracle REST.
         * Les placeholders {xxx} sont résolus via $bindings depuis ExecutionContext.
         * Ex: '/people/{personId}/child/assignments'
         */
        public readonly string $pathTemplate,
        /**
         * Bindings décrivant comment résoudre chaque placeholder.
         *
         * @var list<AncestorBinding>
         */
        public readonly array $bindings = [],
        /** Label lisible pour l'UI (ex: "Relations de travail") */
        public readonly string $label = '',
    ) {
        $this->validateDefinition();
    }

    /**
     * Résout le pathTemplate en URL concrète depuis le contexte d'exécution.
     *
     * @param  array<string, array<string, mixed>>  $context  [resourceId => [field => value]]
     *
     * @throws RuntimeException si un binding ne peut pas être résolu
     */
    public function resolvePath(array $context): string
    {
        $path = $this->pathTemplate;

        foreach ($this->bindings as $binding) {
            $value = $context[$binding->sourceResourceId][$binding->sourceField] ?? null;

            if ($value === null || $value === '') {
                throw new RuntimeException(
                    "Cannot resolve binding '{$binding->placeholder}' for relation '{$this->id}': "
                    ."no value for field '{$binding->sourceField}' in resource '{$binding->sourceResourceId}'.",
                );
            }

            if (! is_string($value) && ! is_int($value)) {
                throw new RuntimeException(
                    "Cannot resolve binding '{$binding->placeholder}' for relation '{$this->id}': "
                    ."field '{$binding->sourceField}' in resource '{$binding->sourceResourceId}' "
                    .'must contain a string or integer identifier.',
                );
            }

            $path = str_replace('{'.$binding->placeholder.'}', rawurlencode((string) $value), $path);
        }

        if (preg_match('/\{[^{}]+\}/', $path, $unresolved) === 1) {
            throw new RuntimeException(
                "Relation '{$this->id}' still contains unresolved placeholder '{$unresolved[0]}'.",
            );
        }

        return $path;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sourceId' => $this->sourceId,
            'targetId' => $this->targetId,
            'type' => $this->type->value,
            'pathTemplate' => $this->pathTemplate,
            'bindings' => array_map(fn (AncestorBinding $b) => $b->toArray(), $this->bindings),
            'label' => $this->label,
        ];
    }

    private function validateDefinition(): void
    {
        if (trim($this->id) === '' || trim($this->sourceId) === '' || trim($this->targetId) === '') {
            throw new InvalidArgumentException('Relation id, source and target must not be empty.');
        }

        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $this->pathTemplate, $matches);

        /** @var list<string> $placeholders */
        $placeholders = array_values(array_unique($matches[1]));
        $bindingPlaceholders = array_map(
            fn (AncestorBinding $binding): string => $binding->placeholder,
            $this->bindings,
        );

        if (count($bindingPlaceholders) !== count(array_unique($bindingPlaceholders))) {
            $duplicates = array_values(array_unique(array_diff_assoc(
                $bindingPlaceholders,
                array_unique($bindingPlaceholders),
            )));

            throw new InvalidArgumentException(
                "Relation '{$this->id}' defines duplicate binding '{$duplicates[0]}'.",
            );
        }

        $missingBindings = array_values(array_diff($placeholders, $bindingPlaceholders));
        if ($missingBindings !== []) {
            throw new InvalidArgumentException(
                "Relation '{$this->id}' has no binding for placeholder '{$missingBindings[0]}'.",
            );
        }

        $unexpectedBindings = array_values(array_diff($bindingPlaceholders, $placeholders));
        if ($unexpectedBindings !== []) {
            throw new InvalidArgumentException(
                "Relation '{$this->id}' binding '{$unexpectedBindings[0]}' has no matching placeholder.",
            );
        }

        $templateWithoutPlaceholders = preg_replace(
            '/\{[A-Za-z][A-Za-z0-9_]*\}/',
            '',
            $this->pathTemplate,
        );

        if (
            $templateWithoutPlaceholders === null
            || str_contains($templateWithoutPlaceholders, '{')
            || str_contains($templateWithoutPlaceholders, '}')
        ) {
            throw new InvalidArgumentException(
                "Relation '{$this->id}' contains a malformed placeholder.",
            );
        }
    }
}
