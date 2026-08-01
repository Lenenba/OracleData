<?php

namespace App\Services;

use App\Enums\QuerySharePermission;
use App\Models\QueryChain;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exécute le chaînage dynamique de requêtes.
 *
 * Étape 13-chaining :
 *   1. Exécute la requête principale (déjà faite par l'appelant, items fournis).
 *   2. Extrait les valeurs de `extraction_field` dans les items.
 *   3. Construit le filtre Oracle REST correspondant selon `injection_operator`.
 *   4. Exécute la requête secondaire avec ce filtre injecté.
 *   5. Retourne les items secondaires enrichis avec le contexte de liaison.
 *
 * Limites de sécurité :
 *  - MAX_INJECTED_VALUES valeurs distinctes injectées (évite les requêtes Oracle trop larges)
 *  - la requête secondaire doit être accessible à l'utilisateur courant
 *  - un seul niveau de chaînage (pas de cascade de chaînes)
 */
class QueryChainService
{
    /** Nombre maximum de valeurs d'IDs injectées dans un filtre IN. */
    public const int MAX_INJECTED_VALUES = 50;

    public function __construct(
        private readonly FusionManager $fusion,
    ) {}

    /**
     * Exécute la requête secondaire d'une chaîne à partir des items primaires.
     *
     * @param  list<array<string, mixed>>  $primaryItems  Items de la requête principale
     * @param  string  $tenantKey  Tenant Oracle à utiliser pour les deux appels
     * @param  User  $user  Utilisateur courant (pour les droits d'accès)
     * @return array{
     *     items: list<array<string, mixed>>,
     *     count: int,
     *     hasMore: bool,
     *     injected_values: list<string|int>,
     *     filter_q: string,
     *     chain_id: int,
     *     extraction_field: string,
     *     injection_param: string,
     * }
     *
     * @throws InvalidArgumentException si la chaîne est invalide ou non autorisée
     * @throws RuntimeException si l'appel Oracle échoue
     */
    public function runSecondary(
        QueryChain $chain,
        array $primaryItems,
        string $tenantKey,
        User $user,
    ): array {
        $secondary = $chain->secondaryQuery;

        if (! $secondary->allows($user, QuerySharePermission::VIEW)) {
            throw new InvalidArgumentException(
                "La requête secondaire [{$secondary->id}] n'est pas accessible.",
            );
        }

        // Extraire les valeurs distinctes du champ d'extraction
        $values = $this->extractValues($primaryItems, $chain->extraction_field);

        if ($values === []) {
            return [
                'items' => [],
                'count' => 0,
                'hasMore' => false,
                'injected_values' => [],
                'filter_q' => '',
                'chain_id' => $chain->id,
                'extraction_field' => $chain->extraction_field,
                'injection_param' => $chain->injection_param,
            ];
        }

        $filterQ = $this->buildFilter(
            $chain->injection_param,
            $chain->injection_operator,
            $values,
        );

        // Construire les paramètres de la requête secondaire en injectant le filtre
        $params = $secondary->parameters ?? [];
        $existing = trim((string) ($params['q'] ?? ''));
        $params['q'] = $existing !== '' ? "({$existing}) AND ({$filterQ})" : $filterQ;
        $params['limit'] = min((int) ($params['limit'] ?? 25), 100);

        $fusion = $this->fusion->forUser($user);

        try {
            $payload = $fusion->tenant($tenantKey)->get(
                (string) $secondary->resource_path,
                $params,
            );
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "Erreur lors de l'exécution de la requête secondaire [{$secondary->id}] : {$e->getMessage()}",
                0,
                $e,
            );
        }

        /** @var list<array<string, mixed>> $items */
        $items = (array) OracleQueryTool::withoutLinks($payload['items'] ?? []);

        return [
            'items' => $items,
            'count' => $payload['count'] ?? count($items),
            'hasMore' => $payload['hasMore'] ?? false,
            'injected_values' => $values,
            'filter_q' => $filterQ,
            'chain_id' => $chain->id,
            'extraction_field' => $chain->extraction_field,
            'injection_param' => $chain->injection_param,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string|int>
     */
    private function extractValues(array $items, string $field): array
    {
        $values = [];

        foreach ($items as $item) {
            $v = $item[$field] ?? null;

            if ($v === null || $v === '') {
                continue;
            }

            $scalar = is_scalar($v) ? $v : null;

            if ($scalar === null) {
                continue;
            }

            $key = (string) $scalar;

            if (! isset($values[$key])) {
                $values[$key] = is_int($v) ? $v : (string) $v;
            }

            if (count($values) >= self::MAX_INJECTED_VALUES) {
                break;
            }
        }

        return array_values($values);
    }

    /**
     * @param  list<string|int>  $values
     */
    private function buildFilter(string $param, string $operator, array $values): string
    {
        if ($values === []) {
            return '';
        }

        if ($operator === 'equals' || count($values) === 1) {
            $v = $values[0];
            $escaped = is_int($v) ? (string) $v : '"'.addslashes((string) $v).'"';

            return "{$param}={$escaped}";
        }

        // Oracle REST IN syntax: param IN (v1,v2,...)
        $list = implode(',', array_map(
            static fn (string|int $v): string => is_int($v) ? (string) $v : '"'.addslashes((string) $v).'"',
            $values,
        ));

        return "{$param} IN ({$list})";
    }
}
