<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

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
        protected OracleFieldDiscovery $discovery,
    ) {}

    /**
     * Valide puis exécute la requête contre le tenant donné.
     *
     * Outre `expand` (enfants Oracle imbriqués, un seul GET), la requête peut
     * demander des `joins` : ressources de premier niveau reliées par une clé
     * du catalogue ({@see OracleResourceCatalog} `join_keys`). Oracle REST ne
     * sachant pas joindre deux ressources racines, chaque jointure déclenche
     * un second GET borné filtré sur les clés collectées, puis les lignes
     * distantes sont imbriquées sous la clé de la jointure dans chaque parent.
     *
     * @param  array<string, mixed>  $query
     * @return array{resource: array<string, mixed>, path: string, params: array<string, mixed>, items: array<int, mixed>, count: int, hasMore: bool, calls: list<array{resource: string, path?: string, params: array<string, mixed>, count: int}>, query: array<string, mixed>}
     *
     * @throws InvalidArgumentException si la ressource, un champ ou une jointure est hors catalogue
     * @throws RuntimeException si Oracle Fusion ne peut pas exécuter la lecture
     */
    public function run(string $tenantKey, array $query): array
    {
        $resourceKey = (string) ($query['resource'] ?? '');
        $resource = $this->catalog->find($resourceKey);

        if ($resource === null) {
            throw new InvalidArgumentException("Ressource Oracle inconnue : [{$resourceKey}].");
        }

        $expand = $this->normalizeList($query['expand'] ?? null);
        $joins = $this->normalizeList($query['joins'] ?? null);
        $this->assertKnown($joins, array_keys($resource['join_keys']), 'ressource jointe (join)', $resource['key']);

        $childFields = $this->normalizeChildFields($query['child_fields'] ?? null, $resource, $expand, $joins, $tenantKey);

        $params = $this->buildParameters($resource, $query, $joins, $tenantKey);

        $fetched = $this->fetchResource($tenantKey, $resource, $params);
        $payload = $fetched['payload'];

        /** @var array<int, mixed> $items */
        $items = self::withoutLinks($payload['items'] ?? []);
        $calls = $fetched['calls'];

        foreach ($joins as $target) {
            $items = $this->attachJoin($tenantKey, $resource, $target, $childFields[$target] ?? [], $items, $calls);
        }

        // Oracle interdit `fields` + `expand` : quand les deux sont demandés on
        // envoie `expand` (enfants complets) et on restreint le parent ici, en
        // ne gardant que les champs demandés + les enfants et jointures imbriqués.
        $requestedFields = $this->normalizeList($query['fields'] ?? null);
        if ($requestedFields !== []) {
            $keep = array_merge($requestedFields, $expand, $joins);
            $items = $this->project($items, $keep);
        }

        foreach ($expand as $child) {
            if (($childFields[$child] ?? []) !== []) {
                $items = $this->projectChild($items, $child, $childFields[$child]);
            }
        }

        return [
            'resource' => $this->catalog->toSuggestion($resource),
            'path' => $fetched['path'],
            'params' => $fetched['params'],
            'items' => $items,
            'count' => $this->payloadCount($payload),
            'hasMore' => $payload['hasMore'] ?? false,
            'calls' => $calls,
            'query' => $this->canonicalQuery($resource['key'], $requestedFields, $expand, $joins, $childFields, $query),
        ];
    }

    /**
     * Tente la ressource principale, puis les fallbacks déclarés dans le
     * catalogue quand Oracle renvoie une collection vide ou refuse l'appel.
     *
     * Certains endpoints Oracle Fusion (notamment les bons de commande)
     * filtrent fortement selon le rôle utilisateur et exposent des finders /
     * vues LOV alternatives. Les fallbacks gardent le wizard utile sans
     * modifier la spécification canonique enregistrée.
     *
     * @param  array{key: string, path: string, fallbacks?: list<array{path: string, params?: array<string, mixed>, strip_params?: list<string>}>, ...}  $resource
     * @param  array<string, mixed>  $params
     * @return array{payload: array<string, mixed>, path: string, params: array<string, mixed>, calls: list<array{resource: string, path: string, params: array<string, mixed>, count: int}>}
     */
    protected function fetchResource(string $tenantKey, array $resource, array $params): array
    {
        $attempts = [[
            'path' => $resource['path'],
            'params' => $params,
        ]];

        foreach (($resource['fallbacks'] ?? []) as $fallback) {
            $attempts[] = [
                'path' => $fallback['path'],
                'params' => $this->fallbackParams($params, $fallback),
            ];
        }

        $calls = [];
        $lastEmpty = null;
        $lastException = null;

        foreach ($attempts as $attempt) {
            $path = (string) $attempt['path'];
            /** @var array<string, mixed> $attemptParams */
            $attemptParams = $attempt['params'];

            try {
                $payload = $this->fusion->tenant($tenantKey)->get($path, $attemptParams);
            } catch (RuntimeException $e) {
                $lastException = $e;

                if (array_key_exists('fields', $attemptParams)) {
                    $fieldlessParams = $attemptParams;
                    unset($fieldlessParams['fields']);

                    try {
                        $payload = $this->fusion->tenant($tenantKey)->get($path, $fieldlessParams);
                    } catch (RuntimeException $fieldlessException) {
                        $lastException = $fieldlessException;

                        continue;
                    }

                    $count = $this->payloadCount($payload);
                    $calls[] = [
                        'resource' => $resource['key'],
                        'path' => $path,
                        'params' => $fieldlessParams,
                        'count' => $count,
                    ];

                    if ($count > 0) {
                        return [
                            'payload' => $payload,
                            'path' => $path,
                            'params' => $fieldlessParams,
                            'calls' => $calls,
                        ];
                    }

                    $lastEmpty = [
                        'payload' => $payload,
                        'path' => $path,
                        'params' => $fieldlessParams,
                        'calls' => $calls,
                    ];
                }

                continue;
            }

            $count = $this->payloadCount($payload);
            $calls[] = [
                'resource' => $resource['key'],
                'path' => $path,
                'params' => $attemptParams,
                'count' => $count,
            ];

            if ($count > 0) {
                return [
                    'payload' => $payload,
                    'path' => $path,
                    'params' => $attemptParams,
                    'calls' => $calls,
                ];
            }

            $lastEmpty = [
                'payload' => $payload,
                'path' => $path,
                'params' => $attemptParams,
                'calls' => $calls,
            ];
        }

        if ($lastEmpty !== null) {
            return $lastEmpty;
        }

        throw $lastException ?? new RuntimeException("Aucune tentative Oracle n'a pu être exécutée.");
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array{params?: array<string, mixed>, strip_params?: list<string>, ...}  $fallback
     * @return array<string, mixed>
     */
    protected function fallbackParams(array $base, array $fallback): array
    {
        foreach (($fallback['strip_params'] ?? []) as $key) {
            unset($base[$key]);
        }

        return array_replace($base, $fallback['params'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function payloadCount(array $payload): int
    {
        if (isset($payload['count']) && is_numeric($payload['count'])) {
            return (int) $payload['count'];
        }

        return count($payload['items'] ?? []);
    }

    /**
     * Spécification canonique de la requête, telle qu'elle peut être persistée
     * puis rejouée à l'identique via {@see run()} (clé `resource_key` incluse).
     *
     * @param  list<string>  $fields
     * @param  list<string>  $expand
     * @param  list<string>  $joins
     * @param  array<string, list<string>>  $childFields
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function canonicalQuery(string $resourceKey, array $fields, array $expand, array $joins, array $childFields, array $query): array
    {
        $spec = ['resource_key' => $resourceKey];

        if ($fields !== []) {
            $spec['fields'] = implode(',', $fields);
        }

        if ($expand !== []) {
            $spec['expand'] = implode(',', $expand);
        }

        if ($joins !== []) {
            $spec['joins'] = implode(',', $joins);
        }

        if ($childFields !== []) {
            $spec['child_fields'] = $childFields;
        }

        $q = isset($query['q']) ? trim((string) $query['q']) : '';
        if ($q !== '') {
            $spec['q'] = $q;
        }

        $orderBy = isset($query['orderBy']) ? trim((string) $query['orderBy']) : '';
        if ($orderBy !== '') {
            $spec['orderBy'] = $orderBy;
        }

        if (isset($query['offset']) && is_numeric($query['offset'])) {
            $spec['offset'] = max(0, (int) $query['offset']);
        }

        $spec['limit'] = $this->catalog->clampLimit($query['limit'] ?? null);

        return $spec;
    }

    /**
     * Exécute la jointure `$target` : GET borné sur la ressource distante
     * filtré sur les valeurs de clé locale collectées, puis imbrique les
     * lignes correspondantes sous `$target` dans chaque ligne parent.
     *
     * Le GET distant est plafonné à 500 lignes : au-delà, certaines lignes
     * parent peuvent recevoir une liste incomplète (`hasMore` côté distant).
     *
     * @param  array{key: string, join_keys: array<string, array{local_key: string, remote_key: string, label: string}>, ...}  $resource
     * @param  list<string>  $joinFields
     * @param  array<int, mixed>  $items
     * @param  list<array{resource: string, path?: string, params: array<string, mixed>, count: int}>  $calls
     * @return array<int, mixed>
     */
    protected function attachJoin(string $tenantKey, array $resource, string $target, array $joinFields, array $items, array &$calls): array
    {
        $targetResource = $this->catalog->find($target);

        if ($targetResource === null) {
            throw new InvalidArgumentException("Ressource jointe inconnue : [{$target}].");
        }

        $localKey = $resource['join_keys'][$target]['local_key'];
        $remoteKey = $resource['join_keys'][$target]['remote_key'];

        $values = [];
        foreach ($items as $item) {
            $value = is_array($item) ? ($item[$localKey] ?? null) : null;

            if (is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '')) {
                $values[(string) $value] = $value;
            }
        }

        if ($values === []) {
            return array_map(
                fn ($item) => is_array($item) ? array_merge($item, [$target => []]) : $item,
                $items,
            );
        }

        // Le quoting suit le type JSON renvoyé par Oracle : un numéro de
        // fournisseur « 79768 » est une chaîne côté Oracle et doit rester
        // quoté, sinon le finder rejette la comparaison typée.
        $conditions = array_map(
            fn ($value): string => is_int($value) || is_float($value)
                ? "{$remoteKey} = {$value}"
                : sprintf("%s = '%s'", $remoteKey, str_replace("'", "''", (string) $value)),
            array_values($values),
        );

        $params = ['limit' => 500];

        if ($joinFields !== []) {
            $fetchFields = in_array($remoteKey, $joinFields, true)
                ? $joinFields
                : array_merge($joinFields, [$remoteKey]);
            $params['fields'] = implode(',', $fetchFields);
        }

        // On filtre la ressource distante sur les clés collectées via un finder
        // multi-valeurs (`key = v1 OR key = v2 …`). Certaines ressources Oracle
        // (ex. purchaseOrders) rejettent l'OR/IN et renvoient un 500 : on
        // retombe alors sur une lecture bornée non filtrée, puis on regroupe
        // localement — jointure au mieux plutôt qu'erreur.
        $usedParams = array_merge($params, ['q' => implode(' OR ', $conditions)]);

        try {
            $fetched = $this->fetchResource($tenantKey, $targetResource, $usedParams);
            $payload = $fetched['payload'];
            $usedParams = $fetched['params'];

            array_push($calls, ...$fetched['calls']);
        } catch (RuntimeException) {
            $usedParams = $params;
            $payload = $this->fusion->tenant($tenantKey)->get($targetResource['path'], $usedParams);

            $calls[] = [
                'resource' => $target,
                'path' => $targetResource['path'],
                'params' => $usedParams,
                'count' => $this->payloadCount($payload),
            ];
        }

        /** @var array<int, mixed> $rows */
        $rows = self::withoutLinks($payload['items'] ?? []);

        $allowed = array_flip($joinFields);
        $grouped = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row[$remoteKey])) {
                continue;
            }

            $groupKey = (string) $row[$remoteKey];
            $grouped[$groupKey][] = $joinFields === [] ? $row : array_intersect_key($row, $allowed);
        }

        return array_map(function ($item) use ($target, $localKey, $grouped) {
            if (! is_array($item)) {
                return $item;
            }

            $value = $item[$localKey] ?? null;
            $item[$target] = $value === null ? [] : ($grouped[(string) $value] ?? []);

            return $item;
        }, $items);
    }

    /**
     * Normalise et valide la sélection de champs par enfant/jointure.
     *
     * Les entrées orphelines (clé ni dans `expand` ni dans `joins`) sont
     * ignorées ; un champ inconnu du catalogue et du tenant est rejeté.
     *
     * @param  array{key: string, child_fields: array<string, list<string>>, ...}  $resource
     * @param  list<string>  $expand
     * @param  list<string>  $joins
     * @return array<string, list<string>>
     */
    protected function normalizeChildFields(mixed $value, array $resource, array $expand, array $joins, string $tenantKey): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $fields) {
            $key = (string) $key;
            $fields = $this->normalizeList($fields);

            if ($fields === []) {
                continue;
            }

            if (in_array($key, $expand, true)) {
                $known = $resource['child_fields'][$key] ?? [];

                if ($known !== []) {
                    $this->assertKnownFields($fields, $known, "champ de l'enfant « {$key} »", $tenantKey, $resource['key'], $key, $resource['key']);
                }
            } elseif (in_array($key, $joins, true)) {
                $target = $this->catalog->find($key);

                if ($target !== null) {
                    $this->assertKnownFields($fields, $target['fields'], "champ de la jointure « {$key} »", $tenantKey, $key, null, $resource['key']);
                }
            } else {
                continue;
            }

            $map[$key] = $fields;
        }

        return $map;
    }

    /**
     * Restreint les lignes d'un enfant imbriqué (`expand`) aux champs demandés,
     * que Oracle renvoie l'enfant en liste directe ou en enveloppe `{items: []}`.
     *
     * @param  array<int, mixed>  $items
     * @param  list<string>  $keep
     * @return array<int, mixed>
     */
    protected function projectChild(array $items, string $child, array $keep): array
    {
        $allowed = array_flip($keep);
        $projectRows = fn (array $rows): array => array_map(
            fn ($row) => is_array($row) ? array_intersect_key($row, $allowed) : $row,
            $rows,
        );

        return array_map(function ($item) use ($child, $projectRows) {
            if (! is_array($item) || ! isset($item[$child]) || ! is_array($item[$child])) {
                return $item;
            }

            $value = $item[$child];

            if (isset($value['items']) && is_array($value['items'])) {
                $value['items'] = $projectRows($value['items']);
            } else {
                $value = $projectRows($value);
            }

            $item[$child] = $value;

            return $item;
        }, $items);
    }

    /**
     * Construit et valide les paramètres REST Oracle.
     *
     * @param  array{key: string, fields: list<string>, child_resources: list<string>, join_keys: array<string, array{local_key: string, remote_key: string, label: string}>, ...}  $resource
     * @param  array<string, mixed>  $query
     * @param  list<string>  $joins
     * @return array<string, mixed>
     */
    protected function buildParameters(array $resource, array $query, array $joins, string $tenantKey): array
    {
        /** @var list<string> $allowedFields */
        $allowedFields = $resource['fields'];
        /** @var list<string> $allowedChildren */
        $allowedChildren = $resource['child_resources'];

        $params = ['limit' => $this->catalog->clampLimit($query['limit'] ?? null)];

        if (isset($query['offset']) && is_numeric($query['offset'])) {
            $params['offset'] = max(0, (int) $query['offset']);
        }

        $expand = $this->normalizeList($query['expand'] ?? null);
        if ($expand !== []) {
            $this->assertKnown($expand, $allowedChildren, 'ressource enfant (expand)', $resource['key']);
            $params['expand'] = implode(',', $expand);
        }

        // `fields` est toujours validé contre le catalogue, mais n'est envoyé à
        // Oracle qu'en l'absence d'`expand` (les deux sont incompatibles côté
        // Oracle). Avec expand, la restriction du parent est faite par projection
        // après réception (voir run()).
        $fields = $this->normalizeList($query['fields'] ?? null);
        if ($fields !== []) {
            $this->assertKnownFields($fields, $allowedFields, 'champ', $tenantKey, $resource['key'], null, $resource['key']);

            if ($expand === []) {
                // Les clés locales des jointures doivent être demandées à Oracle
                // pour permettre le rapprochement ; la projection finale les retire.
                $withJoinKeys = $fields;
                foreach ($joins as $target) {
                    $localKey = $resource['join_keys'][$target]['local_key'];

                    if (! in_array($localKey, $withJoinKeys, true)) {
                        $withJoinKeys[] = $localKey;
                    }
                }

                $params['fields'] = implode(',', $withJoinKeys);
            }
        }

        $orderBy = isset($query['orderBy']) ? trim((string) $query['orderBy']) : '';
        if ($orderBy !== '') {
            $this->assertKnownFields($this->orderByFields($orderBy), $allowedFields, 'champ de tri', $tenantKey, $resource['key'], null, $resource['key']);
            $params['orderBy'] = $orderBy;
        }

        $q = isset($query['q']) ? trim((string) $query['q']) : '';
        if ($q !== '') {
            $this->assertKnownFields($this->qFields($q), $allowedFields, 'champ de filtre', $tenantKey, $resource['key'], null, $resource['key']);
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

        $fields = $matches[1];

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

    /**
     * Validation de noms de champs à deux niveaux : le catalogue d'abord, puis
     * les champs découverts sur le tenant (même cache que l'UI du builder) —
     * le schéma réel dépasse souvent le catalogue. Un champ inconnu des deux
     * reste rejeté avant tout appel Oracle.
     *
     * @param  list<string>  $values
     * @param  list<string>  $catalogFields
     *
     * @throws InvalidArgumentException
     */
    protected function assertKnownFields(
        array $values,
        array $catalogFields,
        string $label,
        string $tenantKey,
        string $probeResourceKey,
        ?string $child,
        string $messageResourceKey,
    ): void {
        $unknown = array_values(array_diff($values, $catalogFields));

        if ($unknown === []) {
            return;
        }

        $userId = $this->fusion->userId();
        $discovered = $userId === null
            ? null
            : $this->discovery->discovered($userId, $tenantKey, $probeResourceKey, $child);

        $this->assertKnown($unknown, $discovered ?? [], $label, $messageResourceKey);
    }

    /**
     * Restreint chaque ligne aux seules clés demandées (champs parent + enfants).
     *
     * @param  array<int, mixed>  $items
     * @param  list<string>  $keep
     * @return array<int, mixed>
     */
    protected function project(array $items, array $keep): array
    {
        $allowed = array_flip($keep);

        return array_map(
            fn ($item) => is_array($item) ? array_intersect_key($item, $allowed) : $item,
            $items,
        );
    }

    /**
     * Retire récursivement les liens HATEOAS (`links`) de la réponse Oracle :
     * chaque ligne et chaque ressource enfant en porte un, inutile à l'affichage
     * et coûteux en tokens pour l'agent.
     */
    public static function withoutLinks(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['links']);

        foreach ($value as $key => $inner) {
            $value[$key] = self::withoutLinks($inner);
        }

        return $value;
    }
}
