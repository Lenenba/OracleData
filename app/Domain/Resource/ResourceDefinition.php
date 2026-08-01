<?php

namespace App\Domain\Resource;

use InvalidArgumentException;

/**
 * Décrit une ressource Oracle Fusion REST de façon générique.
 *
 * ResourceDefinition représente CE QUE L'API ORACLE PERMET.
 * Elle est créée une seule fois (registry) et consultée par le Query Builder.
 *
 * Exemple :
 *   id:             'catalog.people'
 *   name:           'people'
 *   module:         'catalog'
 *   label:          'Personnes'
 *   collectionPath: '/resources/{apiVersion}/people'
 *   itemPath:       '/resources/{apiVersion}/people/{personId}'
 *   apiVersion:     '11.13.18.05'
 */
final class ResourceDefinition
{
    /**
     * @param  list<FieldDefinition>  $fields
     * @param  list<RelationDefinition>  $relations  Relations CHILD/REFERENCE déclarées
     */
    public function __construct(
        /** Identifiant unique global (ex: 'catalog.people', 'catalog.people.assignments') */
        public readonly string $id,
        /** Nom technique Oracle (ex: 'people') */
        public readonly string $name,
        /** Module Oracle (ex: 'hcm', 'procurement', 'financials') */
        public readonly string $module,
        /** Label lisible pour l'UI */
        public readonly string $label,
        /**
         * Template du chemin collection (ex: '/resources/{apiVersion}/people').
         * Les {placeholders} sont résolus lors de l'exécution.
         */
        public readonly string $collectionPath,
        /**
         * Template du chemin item (ex: '/resources/{apiVersion}/people/{personId}').
         */
        public readonly string $itemPath,
        public readonly string $apiVersion,
        public readonly QueryCapabilities $capabilities,
        public readonly array $fields = [],
        public readonly array $relations = [],
        public readonly string $description = '',
    ) {
        $this->validateDefinition();
    }

    /** @return list<RelationDefinition> */
    public function relationsOfType(RelationType $type): array
    {
        return array_values(array_filter(
            $this->relations,
            fn (RelationDefinition $r) => $r->type === $type,
        ));
    }

    /** @return list<RelationDefinition> */
    public function children(): array
    {
        return $this->relationsOfType(RelationType::CHILD);
    }

    /** Retourne un champ par son nom, ou null. */
    public function field(string $name): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(fn (FieldDefinition $f) => $f->name, $this->fields);
    }

    /** @return list<FieldDefinition> */
    public function identifierFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (FieldDefinition $f) => $f->isIdentifier,
        ));
    }

    /** @return list<string> */
    public function identifierFieldNames(): array
    {
        return array_map(
            fn (FieldDefinition $field): string => $field->name,
            $this->identifierFields(),
        );
    }

    /**
     * Extrait depuis une ligne les identifiants déclarés par les FieldDefinition.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|int>
     */
    public function identifiersFromRow(array $row): array
    {
        $identifiers = [];

        foreach ($this->identifierFields() as $field) {
            if (! array_key_exists($field->name, $row)) {
                throw new InvalidArgumentException(
                    "Row for resource '{$this->id}' is missing identifier field '{$field->name}'.",
                );
            }

            $value = $row[$field->name];

            if ((! is_string($value) && ! is_int($value)) || $value === '') {
                throw new InvalidArgumentException(
                    "Identifier field '{$field->name}' for resource '{$this->id}' must be a non-empty string or integer.",
                );
            }

            $identifiers[$field->name] = $value;
        }

        return $identifiers;
    }

    /**
     * Sérialise en array compatible avec l'ancienne structure OracleResource.
     * Permet une rétrocompatibilité avec le code existant qui consomme des arrays.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        $fieldNames = $this->fieldNames();
        $identifierNames = $this->identifierFieldNames();

        $childResources = array_map(
            fn (RelationDefinition $r) => $r->targetId,
            $this->children(),
        );

        return [
            'id' => $this->id,
            'key' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'domain' => $this->module,
            'module' => $this->module,
            'api_version' => $this->apiVersion,
            'collection_path' => $this->collectionPath,
            'item_path' => $this->itemPath,
            'fields' => $fieldNames,
            'identifiers' => $identifierNames,
            'child_resources' => $childResources,
            'capabilities' => $this->capabilities->toArray(),
            'relations' => array_map(fn (RelationDefinition $r) => $r->toArray(), $this->relations),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toLegacyArray();
    }

    private function validateDefinition(): void
    {
        if (trim($this->id) === '' || trim($this->name) === '' || trim($this->module) === '') {
            throw new InvalidArgumentException('Resource id, name and module must not be empty.');
        }

        $fieldNames = $this->fieldNames();
        if (count($fieldNames) !== count(array_unique($fieldNames))) {
            $duplicates = array_values(array_unique(array_diff_assoc(
                $fieldNames,
                array_unique($fieldNames),
            )));

            throw new InvalidArgumentException(
                "Resource '{$this->id}' defines duplicate field '{$duplicates[0]}'.",
            );
        }

        $relationIds = [];
        foreach ($this->relations as $relation) {
            if ($relation->sourceId !== $this->id) {
                throw new InvalidArgumentException(
                    "Relation '{$relation->id}' source '{$relation->sourceId}' does not match resource '{$this->id}'.",
                );
            }

            $relationIds[] = $relation->id;
        }

        if (count($relationIds) !== count(array_unique($relationIds))) {
            throw new InvalidArgumentException(
                "Resource '{$this->id}' defines duplicate relation identifiers.",
            );
        }
    }
}
