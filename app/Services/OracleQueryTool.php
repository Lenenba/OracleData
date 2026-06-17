<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Exécute une requête Oracle structurée en lecture seule (un GET).
 *
 * Garde-fous : seules les ressources du {@see OracleResourceCatalog} sont
 * autorisées, et `fields` / `q` / `orderBy` / `expand` sont validés contre les
 * champs connus de la ressource — un champ inventé par le LLM est rejeté avant
 * tout appel réseau. Les identifiants du tenant sont injectés côté serveur.
 */
class OracleQueryTool
{
    public function __construct(
        protected FusionManager $fusion,
        protected OracleResourceCatalog $catalog,
    ) {}

    /**
     * Valide puis exécute la requête contre le tenant donné.
     *
     * @param  array<string, mixed>  $query
     * @return array{resource: array<string, mixed>, path: string, params: array<string, mixed>, items: array<int, mixed>, count: int, hasMore: bool}
     *
     * @throws InvalidArgumentException si la ressource ou un champ est hors catalogue
     */
    public function run(string $tenantKey, array $query): array
    {
        $resourceKey = (string) ($query['resource'] ?? '');
        $resource = $this->catalog->find($resourceKey);

        if ($resource === null) {
            throw new InvalidArgumentException("Ressource Oracle inconnue : [{$resourceKey}].");
        }

        $params = $this->buildParameters($resource, $query);

        $payload = $this->fusion->tenant($tenantKey)->get($resource['path'], $params);
        $items = $payload['items'] ?? [];

        return [
            'resource' => $this->catalog->toSuggestion($resource),
            'path' => $resource['path'],
            'params' => $params,
            'items' => $items,
            'count' => $payload['count'] ?? count($items),
            'hasMore' => $payload['hasMore'] ?? false,
        ];
    }

    /**
     * Construit et valide les paramètres REST Oracle.
     *
     * @param  array{fields: list<string>, child_resources: list<string>, ...}  $resource
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function buildParameters(array $resource, array $query): array
    {
        /** @var list<string> $allowedFields */
        $allowedFields = $resource['fields'];
        /** @var list<string> $allowedChildren */
        $allowedChildren = $resource['child_resources'];

        $params = ['limit' => $this->catalog->clampLimit($query['limit'] ?? null)];

        if (isset($query['offset']) && is_numeric($query['offset'])) {
            $params['offset'] = max(0, (int) $query['offset']);
        }

        $fields = $this->normalizeList($query['fields'] ?? null);
        if ($fields !== []) {
            $this->assertKnown($fields, $allowedFields, 'champ', $resource['key']);
            $params['fields'] = implode(',', $fields);
        }

        $expand = $this->normalizeList($query['expand'] ?? null);
        if ($expand !== []) {
            $this->assertKnown($expand, $allowedChildren, 'ressource enfant (expand)', $resource['key']);
            $params['expand'] = implode(',', $expand);
        }

        $orderBy = isset($query['orderBy']) ? trim((string) $query['orderBy']) : '';
        if ($orderBy !== '') {
            $this->assertKnown($this->orderByFields($orderBy), $allowedFields, 'champ de tri', $resource['key']);
            $params['orderBy'] = $orderBy;
        }

        $q = isset($query['q']) ? trim((string) $query['q']) : '';
        if ($q !== '') {
            $this->assertKnown($this->qFields($q), $allowedFields, 'champ de filtre', $resource['key']);
            $params['q'] = $q;
        }

        return $params;
    }

    /**
     * Normalise une valeur (string CSV ou liste) en liste de chaînes non vides.
     *
     * @return list<string>
     */
    protected function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item): string => trim((string) $item), $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Champs référencés dans un orderBy (`Field:asc,Field2`).
     *
     * @return list<string>
     */
    protected function orderByFields(string $orderBy): array
    {
        return array_map(
            fn (string $clause): string => trim(explode(':', $clause)[0]),
            explode(',', $orderBy),
        );
    }

    /**
     * Champs référencés dans une expression finder Oracle (`Field op value`).
     *
     * @return list<string>
     */
    protected function qFields(string $q): array
    {
        preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*(?:>=|<=|!=|=|>|<|\bLIKE\b)/i', $q, $matches);

        /** @var list<string> $fields */
        $fields = $matches[1] ?? [];

        return array_values(array_unique($fields));
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $allowed
     *
     * @throws InvalidArgumentException
     */
    protected function assertKnown(array $values, array $allowed, string $label, string $resourceKey): void
    {
        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                throw new InvalidArgumentException("Le {$label} « {$value} » n'existe pas pour la ressource [{$resourceKey}].");
            }
        }
    }
}
