<?php

namespace App\Services;

use App\Enums\SemanticSqlMappingStatus;
use App\Models\SemanticField;
use App\Models\SemanticResource;
use InvalidArgumentException;

/**
 * Resolves only explicitly approved SQL-to-API mappings.
 *
 * Unknown, derived, unsupported, inactive or ambiguous mappings deliberately
 * resolve to null. Technical names are never guessed from an SQL identifier.
 */
class SemanticSqlMappingResolver
{
    public function resolveResource(string $sqlTable): ?SemanticResource
    {
        $normalizedTable = $this->normalizeIdentifier($sqlTable);
        $resources = SemanticResource::query()
            ->where('is_active', true)
            ->where('sql_mapping_status', SemanticSqlMappingStatus::Exact)
            ->where('sql_table', $normalizedTable)
            ->limit(2)
            ->get();

        return $resources->count() === 1 ? $resources->first() : null;
    }

    public function resolveField(
        SemanticResource $resource,
        string $sqlColumn,
        string $childKey = '',
    ): ?SemanticField {
        $normalizedColumn = $this->normalizeIdentifier($sqlColumn);
        $fields = SemanticField::query()
            ->whereBelongsTo($resource, 'semanticResource')
            ->where('child_key', $childKey)
            ->where('is_active', true)
            ->where('sql_mapping_status', SemanticSqlMappingStatus::Exact)
            ->where('sql_expression', $normalizedColumn)
            ->limit(2)
            ->get();

        return $fields->count() === 1 ? $fields->first() : null;
    }

    /**
     * @return array{resource: SemanticResource, field: SemanticField}|null
     */
    public function resolve(string $sqlTable, string $sqlColumn, string $childKey = ''): ?array
    {
        $resource = $this->resolveResource($sqlTable);

        if ($resource === null) {
            return null;
        }

        $field = $this->resolveField($resource, $sqlColumn, $childKey);

        return $field === null ? null : ['resource' => $resource, 'field' => $field];
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_$#]*(?:\.[A-Za-z][A-Za-z0-9_$#]*)?$/', $identifier) !== 1) {
            throw new InvalidArgumentException('L’identifiant SQL doit correspondre exactement à un identifiant autorisé.');
        }

        return mb_strtoupper($identifier);
    }
}
