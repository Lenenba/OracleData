<?php

namespace App\Services;

use App\Enums\OracleExecutionPolicy;
use App\Models\Query;

/**
 * Re-executes one saved query page by page, in the reader's own scope, so an
 * export job can stream an arbitrary volume without holding it all in memory.
 *
 * The scoped {@see FusionManager} and {@see OracleQueryTool} are supplied by the
 * caller so a background job resolves them once for its explicit user.
 */
class QueryExportRunner
{
    /**
     * Fetch one page of results for the query.
     *
     * @return array{items: list<array<string, mixed>>, hasMore: bool}
     */
    public function page(
        FusionManager $fusion,
        OracleQueryTool $tool,
        Query $query,
        string $tenantKey,
        int $offset,
        int $limit,
    ): array {
        $parameters = $query->parameters ?? [];

        // Wizard-built query: replay through the guarded tool so projection,
        // joins and semantic governance are re-applied exactly as at run time.
        if (! empty($parameters['resource_key'])) {
            $result = $tool->run(
                $tenantKey,
                $this->toolQuery($parameters, $offset, $limit),
                $query->execution_policy ?? OracleExecutionPolicy::BEST_EFFORT,
            );

            return [
                'items' => $this->onlyRecords($result['items']),
                'hasMore' => $result['hasMore'],
            ];
        }

        $payload = $fusion->tenant($tenantKey)->get(
            (string) $query->resource_path,
            array_replace($parameters, ['limit' => $limit, 'offset' => $offset]),
        );

        return [
            'items' => $this->onlyRecords(OracleQueryTool::withoutLinks($payload['items'] ?? [])),
            'hasMore' => (bool) ($payload['hasMore'] ?? false),
        ];
    }

    /**
     * Columns to project into the CSV: the explicit wizard field list when
     * present, otherwise the union of keys observed on the first page.
     *
     * @param  list<array<string, mixed>>  $firstPage
     * @return list<string>
     */
    public function columns(Query $query, array $firstPage): array
    {
        $fields = $query->parameters['fields'] ?? null;

        if (is_array($fields) && $fields !== []) {
            return array_values(array_map('strval', $fields));
        }

        $columns = [];

        foreach ($firstPage as $row) {
            foreach (array_keys($row) as $key) {
                $columns[(string) $key] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function toolQuery(array $parameters, int $offset, int $limit): array
    {
        $toolQuery = [
            'resource' => (string) $parameters['resource_key'],
            'fields' => $parameters['fields'] ?? [],
            'expand' => $parameters['expand'] ?? [],
            'joins' => $parameters['joins'] ?? [],
            'child_fields' => $parameters['child_fields'] ?? [],
            'limit' => $limit,
            'offset' => $offset,
        ];

        foreach (['q', 'orderBy'] as $key) {
            if (isset($parameters[$key]) && $parameters[$key] !== '') {
                $toolQuery[$key] = $parameters[$key];
            }
        }

        return $toolQuery;
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function onlyRecords(array $items): array
    {
        return array_values(array_filter($items, 'is_array'));
    }
}
