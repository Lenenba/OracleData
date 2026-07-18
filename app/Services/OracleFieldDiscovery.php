<?php

namespace App\Services;

use App\Models\OracleResourceField;
use App\Models\OracleTenant;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Découvre les champs réellement exposés par une ressource Oracle d'un tenant,
 * via une sonde lecture seule `GET limit=1` (le `/describe` Oracle est trop
 * lourd pour une interaction UI). Le résultat est mis en cache durablement dans
 * `oracle_resource_fields` (par tenant) et resondé au-delà du TTL : Oracle n'est
 * donc sollicité qu'une fois par ressource/tenant, pas à chaque interaction.
 * Les champs du catalogue restent le socle : la sonde les complète, et sert de
 * repli silencieux en cas d'échec Oracle.
 */
class OracleFieldDiscovery
{
    /**
     * Nombre de lignes parcourues pour trouver un enfant non vide : le premier
     * parent n'a pas forcément de lignes enfants.
     */
    private const int CHILD_PROBE_LIMIT = 5;

    /**
     * Mémoïsation par requête HTTP pour éviter de relire la base plusieurs fois
     * quand la même ressource est validée sous plusieurs angles (fields, tri, q).
     *
     * @var array<string, list<string>|null>
     */
    private array $memo = [];

    public function __construct(
        protected FusionManager $fusion,
        protected OracleResourceCatalog $catalog,
    ) {}

    /**
     * Champs de la ressource (ou d'un de ses enfants expand) sur le tenant
     * donné : union ordonnée des champs découverts puis du catalogue.
     *
     * @return array{fields: list<string>, source: 'live'|'catalog'}
     *
     * @throws InvalidArgumentException si la ressource ou l'enfant est hors catalogue
     */
    public function fields(User $user, string $tenantKey, string $resourceKey, ?string $child = null): array
    {
        $resource = $this->catalog->find($resourceKey);

        if ($resource === null) {
            throw new InvalidArgumentException("Ressource Oracle inconnue : [{$resourceKey}].");
        }

        if ($child !== null && ! in_array($child, $resource['child_resources'], true)) {
            throw new InvalidArgumentException("Enfant inconnu pour [{$resourceKey}] : [{$child}].");
        }

        $catalogFields = $child === null
            ? $resource['fields']
            : ($resource['child_fields'][$child] ?? []);

        $discovered = $this->discovered($user->id, $tenantKey, $resourceKey, $child);

        if ($discovered === null) {
            return ['fields' => $catalogFields, 'source' => 'catalog'];
        }

        return [
            'fields' => array_values(array_unique([...$discovered, ...$catalogFields])),
            'source' => 'live',
        ];
    }

    /**
     * Champs sondés sur le tenant, ou null si la ressource est vide ou
     * injoignable. S'appuie sur le cache persistant `oracle_resource_fields`
     * partagé avec l'endpoint resource-fields : la validation d'exécution
     * accepte donc exactement ce que l'UI propose.
     *
     * @return list<string>|null
     */
    public function discovered(int $userId, string $tenantKey, string $resourceKey, ?string $child = null): ?array
    {
        $resource = $this->catalog->find($resourceKey);

        if ($resource === null) {
            return null;
        }

        if ($child !== null && ! in_array($child, $resource['child_resources'], true)) {
            return null;
        }

        $tenant = OracleTenant::query()
            ->where('user_id', $userId)
            ->where('key', $tenantKey)
            ->first();

        if ($tenant === null) {
            return null;
        }

        $childKey = $child ?? '';
        $memoKey = "{$tenant->id}.{$resourceKey}.{$childKey}";

        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $cached = OracleResourceField::query()
            ->where('oracle_tenant_id', $tenant->id)
            ->where('resource_key', $resourceKey)
            ->where('child', $childKey)
            ->first();

        if ($cached !== null && $cached->discovered_at->gt($this->staleBefore())) {
            return $this->memo[$memoKey] = $cached->fields;
        }

        $discovered = $this->probe($this->fusion->forUser($userId), $tenantKey, $resource['path'], $child);

        if ($discovered !== null) {
            OracleResourceField::query()->updateOrCreate(
                [
                    'oracle_tenant_id' => $tenant->id,
                    'resource_key' => $resourceKey,
                    'child' => $childKey,
                ],
                [
                    'fields' => $discovered,
                    'discovered_at' => now(),
                ],
            );
        }

        return $this->memo[$memoKey] = $discovered;
    }

    private function staleBefore(): CarbonInterface
    {
        return now()->subDays((int) config('fusion.fields_ttl_days', 7));
    }

    /**
     * Sonde le tenant et renvoie les clés de la première ligne trouvée,
     * ou null si la ressource est vide ou injoignable.
     *
     * @return list<string>|null
     */
    private function probe(FusionManager $fusion, string $tenantKey, string $path, ?string $child): ?array
    {
        try {
            $payload = $child === null
                ? $fusion->tenant($tenantKey)->get($path, ['limit' => 1, 'onlyData' => 'true'])
                : $fusion->tenant($tenantKey)->get($path, ['limit' => self::CHILD_PROBE_LIMIT, 'expand' => $child]);
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }

        /** @var array<int, mixed> $items */
        $items = $payload['items'] ?? [];

        if ($child === null) {
            $first = $items[0] ?? null;

            return is_array($first) ? $this->fieldKeys($first) : null;
        }

        foreach ($items as $item) {
            $childRows = $this->childRows(is_array($item) ? $item : [], $child);

            if ($childRows !== []) {
                return $this->fieldKeys($childRows[0]);
            }
        }

        return null;
    }

    /**
     * Lignes enfants d'un parent : Oracle enveloppe l'expand dans `{items: []}`
     * mais certaines ressources renvoient directement une liste.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function childRows(array $item, string $child): array
    {
        $value = $item[$child] ?? null;

        if (is_array($value) && array_is_list($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        if (is_array($value) && is_array($value['items'] ?? null)) {
            return array_values(array_filter($value['items'], 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function fieldKeys(array $row): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($row)),
            fn (string $key): bool => $key !== 'links',
        ));
    }
}
