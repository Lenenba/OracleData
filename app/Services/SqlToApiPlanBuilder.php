<?php

namespace App\Services;

use App\Models\SemanticResource;
use RuntimeException;

/**
 * Lot 11C — Traduit un AST SQL en plan d'appels API Oracle REST.
 *
 * Équivalence déterministe uniquement : chaque table/colonne doit avoir un
 * mapping Exact dans la couche sémantique. Toute sélection non mappée produit
 * un fragment "impossible" et l'équivalence globale passe à "partial" ou
 * "impossible".
 *
 * @phpstan-type SqlColumn  array{table: string, column: string, alias: string}
 * @phpstan-type SqlJoin    array{table: string, alias: string, on: string}
 * @phpstan-type SqlAst     array{from_table: string, from_alias: string, columns: list<SqlColumn>, joins: list<SqlJoin>, where: string, order_by: list<array{column: string, direction: string}>, limit: int|null, offset: int|null}
 * @phpstan-type PlanFragment  array{type: string, message: string, sql_fragment: string}
 * @phpstan-type ApiCall       array{resource_key: string, fields: list<string>, q: string, orderBy: string, limit: int|null, offset: int|null, joins: list<string>}
 * @phpstan-type TranslationPlan array{equivalence: string, confidence: int, calls: list<ApiCall>, fragments: list<PlanFragment>, resource_key: string, fields: list<string>, q: string, orderBy: string, limit: int|null, offset: int|null, joins: list<string>}
 */
class SqlToApiPlanBuilder
{
    public function __construct(
        protected SemanticSqlMappingResolver $resolver,
        protected OracleResourceCatalog $catalog,
    ) {}

    /**
     * Build an API execution plan from a parsed SQL AST.
     *
     * @param  array{from_table: string, from_alias: string, columns: list<array{table: string, column: string, alias: string}>, joins: list<array{table: string, alias: string, on: string}>, where: string, order_by: list<array{column: string, direction: string}>, limit: int|null, offset: int|null}  $ast
     * @return array{equivalence: string, confidence: int, calls: list<array{resource_key: string, fields: list<string>, q: string, orderBy: string, limit: int|null, offset: int|null, joins: list<string>}>, fragments: list<array{type: string, message: string, sql_fragment: string}>, resource_key: string, fields: list<string>, q: string, orderBy: string, limit: int|null, offset: int|null, joins: list<string>}
     *
     * @throws RuntimeException when the root table has no semantic mapping
     */
    public function build(array $ast): array
    {
        $fragments = [];
        $equivalence = 'exact';

        // ── 1. Resolve the primary FROM table ────────────────────────────────
        $fromTable = $ast['from_table'];
        $fromAlias = $ast['from_alias'];
        $primaryResource = $this->resolver->resolveResource($fromTable);

        if ($primaryResource === null) {
            throw new RuntimeException('sqlTranslator.errorTableNotMapped');
        }

        $resourceKey = $primaryResource->resource_key;
        $catalogResource = $this->catalog->find($resourceKey);

        if ($catalogResource === null) {
            throw new RuntimeException('sqlTranslator.errorTableNotMapped');
        }

        // ── 2. Resolve columns ───────────────────────────────────────────────
        $fields = [];
        $isSelectStar = false;

        foreach ($ast['columns'] as $col) {
            if ($col['column'] === '*') {
                $isSelectStar = true;

                continue;
            }

            // Determine which table this column belongs to
            $colTable = $col['table'] !== '' ? $col['table'] : ($fromAlias !== '' ? null : $fromTable);

            if ($colTable !== null && $colTable !== $fromTable && $colTable !== $fromAlias) {
                // It belongs to a joined table — handled below, skip here
                continue;
            }

            $resolved = $this->resolver->resolveField($primaryResource, $col['column']);

            if ($resolved !== null) {
                $fields[] = $resolved->source_name;
            } else {
                $equivalence = $this->downgrade($equivalence, 'partial');
                $fragments[] = [
                    'type' => 'partial',
                    'message' => 'sqlTranslator.fragmentColumnNotMapped',
                    'sql_fragment' => $col['table'] !== '' ? "{$col['table']}.{$col['column']}" : $col['column'],
                ];
            }
        }

        if ($isSelectStar) {
            // Use all allowed fields from the catalog
            $fields = $catalogResource['fields'];
        }

        // ── 3. Resolve JOINs ─────────────────────────────────────────────────
        $resolvedJoins = [];
        $aliasMap = [$fromAlias => $fromTable, $fromTable => $fromTable];

        foreach ($ast['joins'] as $join) {
            $joinTable = $join['table'];
            $joinAlias = $join['alias'];

            if ($joinAlias !== '') {
                $aliasMap[$joinAlias] = $joinTable;
            }

            $joinResource = $this->resolver->resolveResource($joinTable);

            if ($joinResource === null) {
                $equivalence = $this->downgrade($equivalence, 'partial');
                $fragments[] = [
                    'type' => 'partial',
                    'message' => 'sqlTranslator.fragmentJoinNotMapped',
                    'sql_fragment' => "JOIN {$joinTable}",
                ];

                continue;
            }

            $joinKey = $joinResource->resource_key;

            // Check that the catalog declares this join
            if (! isset($catalogResource['join_keys'][$joinKey])) {
                $equivalence = $this->downgrade($equivalence, 'partial');
                $fragments[] = [
                    'type' => 'partial',
                    'message' => 'sqlTranslator.fragmentJoinNotDeclared',
                    'sql_fragment' => "JOIN {$joinTable}",
                ];

                continue;
            }

            $resolvedJoins[] = $joinKey;
        }

        // ── 4. Translate WHERE clause ─────────────────────────────────────────
        $translatedQ = '';

        if ($ast['where'] !== '') {
            [$translatedQ, $whereEquivalence, $whereFragments] = $this->translateWhere(
                $ast['where'],
                $primaryResource,
                $aliasMap,
                $fromTable,
                $fromAlias,
            );
            $equivalence = $this->downgrade($equivalence, $whereEquivalence);
            $fragments = array_merge($fragments, $whereFragments);
        }

        // ── 5. Translate ORDER BY ─────────────────────────────────────────────
        $translatedOrderBy = '';

        if ($ast['order_by'] !== []) {
            $orderClauses = [];

            foreach ($ast['order_by'] as $ord) {
                $colExpr = $ord['column'];
                // Strip table prefix
                if (str_contains($colExpr, '.')) {
                    $colExpr = explode('.', $colExpr)[1];
                }

                $ordField = $this->resolver->resolveField($primaryResource, mb_strtoupper($colExpr));

                if ($ordField !== null) {
                    $orderClauses[] = $ordField->source_name.':'.mb_strtolower($ord['direction']);
                } else {
                    $equivalence = $this->downgrade($equivalence, 'partial');
                    $fragments[] = [
                        'type' => 'partial',
                        'message' => 'sqlTranslator.fragmentOrderByNotMapped',
                        'sql_fragment' => $ord['column'],
                    ];
                }
            }

            if ($orderClauses !== []) {
                $translatedOrderBy = implode(',', $orderClauses);
            }
        }

        // ── 6. Limit / offset ─────────────────────────────────────────────────
        $limit = $ast['limit'];
        $offset = $ast['offset'];

        // Clamp limit to catalog safe maximum
        if ($limit !== null) {
            $limit = $this->catalog->clampLimit($limit);
        }

        // ── 7. Compute confidence ─────────────────────────────────────────────
        $confidence = match ($equivalence) {
            'exact' => 100,
            'partial' => 60,
            default => 0,
        };

        $call = [
            'resource_key' => $resourceKey,
            'fields' => $fields,
            'q' => $translatedQ,
            'orderBy' => $translatedOrderBy,
            'limit' => $limit,
            'offset' => $offset,
            'joins' => $resolvedJoins,
        ];

        return [
            'equivalence' => $equivalence,
            'confidence' => $confidence,
            'calls' => [$call],
            'fragments' => $fragments,
            // Top-level shortcut (single-call path):
            'resource_key' => $resourceKey,
            'fields' => $fields,
            'q' => $translatedQ,
            'orderBy' => $translatedOrderBy,
            'limit' => $limit,
            'offset' => $offset,
            'joins' => $resolvedJoins,
        ];
    }

    // ─── private helpers ──────────────────────────────────────────────────────

    /**
     * Translate a SQL WHERE clause to an Oracle REST finder expression.
     *
     * Rules:
     *  - Simple comparisons: col = 'val', col > val, col LIKE 'val%'
     *  - IS NULL / IS NOT NULL → not translatable (Oracle REST finders do not support IS NULL)
     *  - IN (…) → not translatable as a single q= expression; flagged as partial
     *  - AND/OR boolean connectors are preserved as-is
     *
     * @param  array<string, string>  $aliasMap  table alias → real table name
     * @return array{0: string, 1: string, 2: list<array{type: string, message: string, sql_fragment: string}>}
     */
    private function translateWhere(
        string $where,
        SemanticResource $resource,
        array $aliasMap,
        string $fromTable,
        string $fromAlias,
    ): array {
        $fragments = [];
        $equivalence = 'exact';

        // Match individual conditions and translate column names
        $translated = preg_replace_callback(
            '/([A-Za-z_][A-Za-z0-9_$#]*)(?:\.([A-Za-z_][A-Za-z0-9_$#]*))?\s*(>=|<=|!=|<>|=|>|<|LIKE|IS\s+NOT\s+NULL|IS\s+NULL|IN\s*\()/i',
            function (array $m) use ($resource, $fromTable, $fromAlias, &$fragments, &$equivalence) {
                $tableOrAlias = $m[2] !== '' ? mb_strtoupper($m[1]) : '';
                $colName = $m[2] !== '' ? mb_strtoupper($m[2]) : mb_strtoupper($m[1]);
                $operator = trim($m[3]);

                // IS NULL / IS NOT NULL → flag as impossible
                if (preg_match('/IS\s+(NOT\s+)?NULL/i', $operator)) {
                    $equivalence = $this->downgrade($equivalence, 'partial');
                    $fragments[] = [
                        'type' => 'partial',
                        'message' => 'sqlTranslator.fragmentIsNullNotSupported',
                        'sql_fragment' => ($tableOrAlias !== '' ? "{$tableOrAlias}.{$colName}" : $colName)." {$operator}",
                    ];

                    return $m[0]; // Keep as-is (will be flagged partial)
                }

                // IN(…) → flag as partial (Oracle REST supports q= IN only partially)
                if (preg_match('/IN\s*\(/i', $operator)) {
                    $equivalence = $this->downgrade($equivalence, 'partial');
                    $fragments[] = [
                        'type' => 'partial',
                        'message' => 'sqlTranslator.fragmentInNotSupported',
                        'sql_fragment' => $colName." {$operator}",
                    ];

                    return $m[0];
                }

                // If a table prefix is given, check it matches the primary table
                if ($tableOrAlias !== '' && ! in_array($tableOrAlias, [$fromTable, $fromAlias, ''], true)) {
                    // Belongs to a joined table — leave as-is and flag partial
                    $equivalence = $this->downgrade($equivalence, 'partial');
                    $fragments[] = [
                        'type' => 'partial',
                        'message' => 'sqlTranslator.fragmentJoinConditionSkipped',
                        'sql_fragment' => "{$tableOrAlias}.{$colName} {$operator}",
                    ];

                    return $m[0];
                }

                // Resolve column
                $field = $this->resolver->resolveField($resource, $colName);

                if ($field === null) {
                    $equivalence = $this->downgrade($equivalence, 'partial');
                    $fragments[] = [
                        'type' => 'partial',
                        'message' => 'sqlTranslator.fragmentColumnNotMapped',
                        'sql_fragment' => $colName." {$operator}",
                    ];

                    return $m[0];
                }

                // Normalize Oracle REST operator (= → =, <> / != → !=, LIKE → LIKE)
                $oracleOp = match (mb_strtoupper($operator)) {
                    '<>' => '!=',
                    default => $operator,
                };

                return $field->source_name.' '.$oracleOp;
            },
            $where,
        ) ?? $where;

        return [$translated, $equivalence, $fragments];
    }

    /**
     * Downgrade an equivalence level: exact > partial > impossible.
     */
    private function downgrade(string $current, string $candidate): string
    {
        $rank = ['exact' => 2, 'partial' => 1, 'impossible' => 0];

        if (($rank[$candidate] ?? 0) < ($rank[$current] ?? 2)) {
            return $candidate;
        }

        return $current;
    }
}
