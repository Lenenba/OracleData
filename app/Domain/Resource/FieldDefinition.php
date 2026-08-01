<?php

namespace App\Domain\Resource;

/**
 * Décrit un champ exposé par une ResourceDefinition.
 *
 * - name        : nom Oracle (PascalCase, ex: PersonNumber)
 * - type        : type de donnée Oracle (string, integer, date, datetime, boolean)
 * - queryable   : peut être utilisé dans un filtre Oracle (paramètre q=)
 * - sortable    : peut être utilisé dans orderBy
 * - isIdentifier: fait partie des identifiants de l'URL (ex: personId)
 * - description : label lisible, optionnel
 */
final class FieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly bool $queryable = true,
        public readonly bool $sortable = true,
        public readonly bool $isIdentifier = false,
        public readonly string $description = '',
    ) {}

    public static function identifier(string $name, string $type = 'integer'): self
    {
        return new self(
            name: $name,
            type: $type,
            queryable: true,
            sortable: false,
            isIdentifier: true,
        );
    }

    public static function string(string $name, bool $queryable = true): self
    {
        return new self(name: $name, type: 'string', queryable: $queryable);
    }

    public static function date(string $name): self
    {
        return new self(name: $name, type: 'date', queryable: true, sortable: true);
    }

    public static function datetime(string $name): self
    {
        return new self(name: $name, type: 'datetime', queryable: true, sortable: true);
    }

    public static function boolean(string $name): self
    {
        return new self(name: $name, type: 'boolean', queryable: true, sortable: false);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'queryable' => $this->queryable,
            'sortable' => $this->sortable,
            'isIdentifier' => $this->isIdentifier,
            'description' => $this->description,
        ];
    }
}
