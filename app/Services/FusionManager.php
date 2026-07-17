<?php

namespace App\Services;

use App\Models\OracleTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Résout un {@see FusionClient} par tenant (client).
 *
 * Multi-tenant : chaque client possède son propre environnement Oracle Fusion
 * (URL + compte de service). Le tenant cible est choisi à l'exécution.
 * Les tenants en base priment sur `config/fusion.php`, qui reste un fallback.
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
     * Combined tenant configuration resolved once for the current application request.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $resolvedTenants = null;

    /**
     * Active database tenants resolved once for the current application request.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $resolvedDatabaseTenants = null;

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
        return $this->tenant($this->defaultKey());
    }

    /**
     * Indique si un tenant est configuré.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->tenants());
    }

    /**
     * Clé du tenant par défaut, ou le premier disponible.
     */
    public function defaultKey(): string
    {
        foreach ($this->databaseTenants() as $key => $tenant) {
            if ($tenant['is_default'] ?? false) {
                return $key;
            }
        }

        $configuredDefault = (string) config('fusion.default');

        if ($configuredDefault !== '' && $this->has($configuredDefault)) {
            return $configuredDefault;
        }

        return (string) array_key_first($this->tenants());
    }

    /**
     * Liste des clés de tenants utilisables pour la validation.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->tenants());
    }

    /**
     * Liste des tenants disponibles, sous la forme `clé => libellé` (pour l'UI).
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        return (new Collection($this->tenants()))
            ->map(fn (array $config, string $key): string => $config['label'] ?? $key)
            ->all();
    }

    /**
     * Détails non sensibles des tenants pour la page de configuration.
     *
     * @return array<int, array{id: int|null, key: string, label: string, base_url: string, username: string, source: string, is_default: bool, is_active: bool}>
     */
    public function details(): array
    {
        return (new Collection($this->tenants()))
            ->map(fn (array $config, string $key): array => [
                'id' => $config['id'] ?? null,
                'key' => $key,
                'label' => (string) ($config['label'] ?? $key),
                'base_url' => (string) ($config['base_url'] ?? ''),
                'username' => (string) ($config['username'] ?? ''),
                'source' => $config['source'] ?? 'config',
                'is_default' => $key === $this->defaultKey(),
                'is_active' => (bool) ($config['is_active'] ?? true),
            ])
            ->values()
            ->all();
    }

    /**
     * Libellé d'un tenant.
     */
    public function label(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $tenant = $this->tenants()[$key] ?? null;

        return $tenant['label'] ?? $key;
    }

    /**
     * Instancie un client à partir de la config d'un tenant.
     */
    protected function build(string $key): FusionClient
    {
        /** @var array{base_url?: string, username?: string, password?: string} $config */
        $config = $this->tenants()[$key];

        return new FusionClient(
            baseUrl: (string) ($config['base_url'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
        );
    }

    /**
     * Configuration fusion combinée : config locale + tenants enregistrés.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function tenants(): array
    {
        return $this->resolvedTenants ??= array_replace($this->configuredTenants(), $this->databaseTenants());
    }

    /**
     * Forget memoized tenant configuration after an administrative mutation.
     */
    public function forgetResolvedTenants(): void
    {
        $this->resolvedTenants = null;
        $this->resolvedDatabaseTenants = null;
        $this->clients = [];
    }

    /**
     * Tenants déclarés dans config/fusion.php.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function configuredTenants(): array
    {
        /** @var array<string, array<string, mixed>> $tenants */
        $tenants = config('fusion.tenants', []);

        return (new Collection($tenants))
            ->map(fn (array $config): array => [
                ...$config,
                'source' => 'config',
                'is_active' => true,
            ])
            ->all();
    }

    /**
     * Tenants actifs enregistrés en base.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function databaseTenants(): array
    {
        if ($this->resolvedDatabaseTenants !== null) {
            return $this->resolvedDatabaseTenants;
        }

        try {
            if (! Schema::hasTable('oracle_tenants')) {
                return $this->resolvedDatabaseTenants = [];
            }

            return $this->resolvedDatabaseTenants = OracleTenant::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get()
                ->mapWithKeys(fn (OracleTenant $tenant): array => [
                    $tenant->key => [
                        'id' => $tenant->id,
                        'label' => $tenant->label,
                        'base_url' => $tenant->base_url,
                        'username' => $tenant->username,
                        'password' => $tenant->password,
                        'source' => 'database',
                        'is_default' => $tenant->is_default,
                        'is_active' => $tenant->is_active,
                    ],
                ])
                ->all();
        } catch (Throwable) {
            return $this->resolvedDatabaseTenants = [];
        }
    }
}
