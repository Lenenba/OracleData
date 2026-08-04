<?php

namespace App\Services;

use RuntimeException;

/**
 * Lot 11C — Parseur SQL déterministe vers un AST minimal.
 *
 * Périmètre strict : SELECT ANSI simple. Tout le reste lève une
 * {@see RuntimeException} avec un message localisable.
 *
 * Syntaxe prise en charge :
 *  - SELECT col[, col] FROM table [alias] [JOIN table [alias] ON cond …]
 *  - WHERE expr (=, !=, <, >, <=, >=, LIKE, IS NULL, IS NOT NULL, IN(…))
 *  - ORDER BY col [ASC|DESC] [, …]
 *  - LIMIT n [OFFSET m]
 *  - Alias de colonnes (AS)
 *  - SELECT * et SELECT table.* et SELECT table.col
 *
 * Refusés : INSERT, UPDATE, DELETE, DDL, transactions, CTE, sous-requêtes
 * corrélées, fonctions analytiques/fenêtres, UNION, INTERSECT, EXCEPT,
 * requêtes multiples séparées par `;`.
 *
 * @phpstan-type SqlColumn  array{table: string, column: string, alias: string}
 * @phpstan-type SqlJoin    array{table: string, alias: string, on: string}
 * @phpstan-type SqlAst     array{from_table: string, from_alias: string, columns: list<SqlColumn>, joins: list<SqlJoin>, where: string, order_by: list<array{column: string, direction: string}>, limit: int|null, offset: int|null}
 */
class SqlQueryParser
{
    private const array REFUSED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'GRANT', 'REVOKE', 'COMMIT', 'ROLLBACK', 'BEGIN',
        'DECLARE', 'EXECUTE', 'EXEC', 'CALL', 'PROCEDURE', 'FUNCTION',
    ];

    private const array UNSUPPORTED_CLAUSES = [
        'UNION', 'INTERSECT', 'EXCEPT', 'WITH ', 'OVER(', 'OVER (',
        'PARTITION BY', 'WINDOW ', 'PIVOT', 'UNPIVOT',
    ];

    /**
     * Parse a single SELECT SQL statement and return a typed AST array.
     *
     * @return array{from_table: string, from_alias: string, columns: list<array{table: string, column: string, alias: string}>, joins: list<array{table: string, alias: string, on: string}>, where: string, order_by: list<array{column: string, direction: string}>, limit: int|null, offset: int|null}
     *
     * @throws RuntimeException on any unsupported or dangerous construct
     */
    public function parse(string $sql): array
    {
        $sql = $this->normalize($sql);

        $this->assertSafeStatement($sql);

        return [
            'from_table' => $this->extractFromTable($sql),
            'from_alias' => $this->extractFromAlias($sql),
            'columns' => $this->extractColumns($sql),
            'joins' => $this->extractJoins($sql),
            'where' => $this->extractWhere($sql),
            'order_by' => $this->extractOrderBy($sql),
            'limit' => $this->extractLimit($sql),
            'offset' => $this->extractOffset($sql),
        ];
    }

    // ─── private helpers ──────────────────────────────────────────────────────

    private function normalize(string $sql): string
    {
        // Collapse whitespace, preserve literals
        $sql = trim($sql);

        // Strip trailing semicolons
        $sql = rtrim($sql, "; \t\n\r\0\x0B");

        // Remove single-line comments (-- …)
        $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;

        // Remove multi-line comments (/* … */)
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;

        // Collapse multiple spaces
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return trim($sql);
    }

    private function assertSafeStatement(string $sql): void
    {
        $upper = mb_strtoupper($sql);

        // Reject multiple statements
        if (str_contains($sql, ';')) {
            throw new RuntimeException('sqlTranslator.errorMultipleStatements');
        }

        // Reject non-SELECT statements
        foreach (self::REFUSED_KEYWORDS as $keyword) {
            // Word-boundary match
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $upper) === 1) {
                throw new RuntimeException('sqlTranslator.errorMutationDetected');
            }
        }

        // Must start with SELECT
        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new RuntimeException('sqlTranslator.errorNotASelect');
        }

        // Reject unsupported clauses
        foreach (self::UNSUPPORTED_CLAUSES as $clause) {
            if (str_contains($upper, mb_strtoupper($clause))) {
                throw new RuntimeException('sqlTranslator.errorUnsupportedClause');
            }
        }

        // Reject correlated subqueries (simplified: nested SELECT)
        if (preg_match_all('/\bSELECT\b/i', $sql) > 1) {
            throw new RuntimeException('sqlTranslator.errorSubquery');
        }
    }

    private function extractFromTable(string $sql): string
    {
        if (preg_match('/\bFROM\s+([A-Za-z_][A-Za-z0-9_$#]*)/i', $sql, $m)) {
            return mb_strtoupper($m[1]);
        }

        throw new RuntimeException('sqlTranslator.errorNoFromClause');
    }

    private function extractFromAlias(string $sql): string
    {
        // FROM table [AS] alias WHERE|JOIN|ORDER|LIMIT|$
        if (preg_match(
            '/\bFROM\s+[A-Za-z_][A-Za-z0-9_$#]*\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_$#]*)\s*(?:WHERE|JOIN|ORDER|LIMIT|LEFT|RIGHT|INNER|OUTER|CROSS|ON|$)/i',
            $sql,
            $m,
        )) {
            $candidate = mb_strtoupper($m[1]);
            // Exclude SQL keywords that could be mistaken for aliases
            $reserved = ['WHERE', 'JOIN', 'ORDER', 'LIMIT', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'ON', 'GROUP', 'HAVING'];
            if (! in_array($candidate, $reserved, true)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return list<array{table: string, column: string, alias: string}>
     */
    private function extractColumns(string $sql): array
    {
        // Extract the SELECT … FROM portion
        if (! preg_match('/\bSELECT\s+(.*?)\s+FROM\b/is', $sql, $m)) {
            throw new RuntimeException('sqlTranslator.errorParsingColumns');
        }

        $raw = $m[1];
        $parts = $this->splitCsv($raw);
        $columns = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // col AS alias  or  col alias
            if (preg_match('/^(.*?)\s+AS\s+([A-Za-z_][A-Za-z0-9_$#]*)\s*$/i', $part, $m2)) {
                [, $colExpr, $alias] = $m2;
            } elseif (preg_match('/^([A-Za-z_.$*][A-Za-z0-9_.$#*]*)\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/i', $part, $m2)) {
                [, $colExpr, $alias] = $m2;
            } else {
                $colExpr = $part;
                $alias = '';
            }

            $colExpr = trim($colExpr);

            // table.column or table.*
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_$#]*)\.(\*|[A-Za-z_][A-Za-z0-9_$#]*)$/', $colExpr, $m3)) {
                $columns[] = [
                    'table' => mb_strtoupper($m3[1]),
                    'column' => mb_strtoupper($m3[2]),
                    'alias' => mb_strtoupper($alias),
                ];
            } else {
                $columns[] = [
                    'table' => '',
                    'column' => mb_strtoupper($colExpr),
                    'alias' => mb_strtoupper($alias),
                ];
            }
        }

        return $columns;
    }

    /**
     * @return list<array{table: string, alias: string, on: string}>
     */
    private function extractJoins(string $sql): array
    {
        $joins = [];

        preg_match_all(
            '/\b(?:LEFT\s+(?:OUTER\s+)?|RIGHT\s+(?:OUTER\s+)?|INNER\s+|CROSS\s+)?JOIN\s+([A-Za-z_][A-Za-z0-9_$#]*)\s*(?:(?:AS\s+)?([A-Za-z_][A-Za-z0-9_$#]*)\s+)?ON\s+(.*?)(?=\b(?:LEFT|RIGHT|INNER|CROSS|JOIN|WHERE|ORDER|LIMIT|$))/is',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            $table = mb_strtoupper(trim($m[1]));
            $alias = $m[2] !== '' ? mb_strtoupper(trim($m[2])) : '';
            $on = trim($m[3]);

            $reserved = ['WHERE', 'ORDER', 'LIMIT', 'ON', 'LEFT', 'RIGHT', 'INNER', 'JOIN', 'OUTER', 'CROSS'];

            if (in_array(mb_strtoupper($alias), $reserved, true)) {
                $alias = '';
            }

            $joins[] = ['table' => $table, 'alias' => $alias, 'on' => $on];
        }

        return $joins;
    }

    private function extractWhere(string $sql): string
    {
        if (! preg_match('/\bWHERE\s+(.*?)(?:\bORDER\s+BY\b|\bLIMIT\b|\bGROUP\s+BY\b|\bHAVING\b|$)/is', $sql, $m)) {
            return '';
        }

        return trim($m[1]);
    }

    /**
     * @return list<array{column: string, direction: string}>
     */
    private function extractOrderBy(string $sql): array
    {
        if (! preg_match('/\bORDER\s+BY\s+(.*?)(?:\bLIMIT\b|$)/is', $sql, $m)) {
            return [];
        }

        $parts = $this->splitCsv($m[1]);
        $result = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z_.][A-Za-z0-9_.$#]*)\s*(ASC|DESC)?$/i', $part, $m2)) {
                $result[] = [
                    'column' => mb_strtoupper($m2[1]),
                    'direction' => mb_strtoupper($m2[2] ?? 'ASC'),
                ];
            }
        }

        return $result;
    }

    private function extractLimit(string $sql): ?int
    {
        if (preg_match('/\bLIMIT\s+(\d+)/i', $sql, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractOffset(string $sql): ?int
    {
        if (preg_match('/\bOFFSET\s+(\d+)/i', $sql, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Split a comma-separated expression list, respecting parentheses depth.
     *
     * @return list<string>
     */
    private function splitCsv(string $expr): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $len = strlen($expr); $i < $len; $i++) {
            $char = $expr[$i];

            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
