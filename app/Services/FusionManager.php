<?php

namespace App\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Résout un {@see FusionClient} par tenant (client) à partir de `config/fusion.php`.
 *
 * Multi-tenant : chaque client possède son propre environnement Oracle Fusion
 * (URL + compte de service). Le tenant cible est choisi à l'exécution.
 */
class FusionManager
{
    /**
     * Clients déjà instanciés, mémoïsés par clé de tenant.
     *
     * @var array<string, FusionClient>
     */
    protected array $clients = [];

    /**
     * Résout le client d'un tenant configuré.
     *
     * @throws InvalidArgumentException si la clé de tenant est inconnue
     */
    public function tenant(string $key): FusionClient
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Tenant Fusion inconnu : [{$key}].");
        }

        return $this->clients[$key] ??= $this->build($key);
    }

    /**
     * Résout le client du tenant par défaut (`config('fusion.default')`).
     */
    public function default(): FusionClient
    {
        return $this->tenant((string) config('fusion.default'));
    }

    /**
     * Indique si un tenant est configuré.
     */
    public function has(string $key): bool
    {
        return is_array(config("fusion.tenants.{$key}"));
    }

    /**
     * Liste des tenants disponibles, sous la forme `clé => libellé` (pour l'UI).
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        /** @var array<string, array{label?: string}> $tenants */
        $tenants = config('fusion.tenants', []);

        return (new Collection($tenants))
            ->map(fn (array $config, string $key): string => $config['label'] ?? $key)
            ->all();
    }

    /**
     * Instancie un client à partir de la config d'un tenant.
     */
    protected function build(string $key): FusionClient
    {
        /** @var array{base_url?: string, username?: string, password?: string} $config */
        $config = config("fusion.tenants.{$key}");

        return new FusionClient(
            baseUrl: (string) ($config['base_url'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
        );
    }
}
