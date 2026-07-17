<?php

namespace App\Services;

use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Resolve Oracle clients exclusively from the current user's active
 * environments and authentication connections.
 */
class FusionManager
{
    /** @var array<string, FusionClient> */
    protected array $clients = [];

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $resolvedActiveTenants = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $resolvedManagedTenants = null;

    protected ?int $explicitUserId = null;

    protected ?int $resolvedForUserId = null;

    /**
     * Return an isolated resolver context for jobs, commands and explicit
     * application flows that cannot rely on the web authentication guard.
     */
    public function forUser(User|int $user): self
    {
        $scoped = clone $this;
        $scoped->explicitUserId = $user instanceof User ? $user->id : $user;
        $scoped->forgetResolvedTenants();

        return $scoped;
    }

    /**
     * @throws InvalidArgumentException when the environment is not owned,
     *                                  active and backed by a verified connection
     */
    public function tenant(string $key): FusionClient
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Tenant Fusion inconnu ou non autorisé : [{$key}].");
        }

        return $this->clients[$key] ??= $this->build($key);
    }

    public function default(): FusionClient
    {
        $key = $this->defaultKey();

        if ($key === '') {
            throw new InvalidArgumentException('Aucune connexion Oracle active n’est disponible.');
        }

        return $this->tenant($key);
    }

    public function has(?string $key): bool
    {
        return $key !== null
            && $key !== ''
            && array_key_exists($key, $this->activeTenants());
    }

    public function defaultKey(): string
    {
        foreach ($this->activeTenants() as $key => $tenant) {
            if ($tenant['is_default'] ?? false) {
                return $key;
            }
        }

        return (string) (array_key_first($this->activeTenants()) ?? '');
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->activeTenants());
    }

    /** @return array<string, string> */
    public function available(): array
    {
        return (new Collection($this->activeTenants()))
            ->map(fn (array $config, string $key): string => (string) ($config['label'] ?? $key))
            ->all();
    }

    /**
     * Non-sensitive details for the owner's connection settings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function details(): array
    {
        return (new Collection($this->managedTenants()))
            ->map(fn (array $config, string $key): array => [
                'id' => $config['id'],
                'key' => $key,
                'label' => (string) $config['label'],
                'base_url' => (string) $config['base_url'],
                'username' => (string) ($config['username'] ?? ''),
                'source' => 'database',
                'auth_type' => (string) ($config['auth_type'] ?? 'basic'),
                'connection_count' => (int) ($config['connection_count'] ?? 0),
                'verified_at' => $config['verified_at']?->toISOString(),
                'last_tested_at' => $config['last_tested_at']?->toISOString(),
                'is_default' => (bool) $config['is_default'],
                'is_active' => (bool) $config['is_active']
                    && (bool) ($config['connection_is_active'] ?? false)
                    && (bool) ($config['connection_is_supported'] ?? false)
                    && $config['verified_at'] !== null,
            ])
            ->values()
            ->all();
    }

    public function label(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->managedTenants()[$key]['label'] ?? null;
    }

    public function tenantId(?string $key): ?int
    {
        if ($key === null || $key === '') {
            return null;
        }

        $id = $this->activeTenants()[$key]['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function forgetResolvedTenants(): void
    {
        $this->resolvedActiveTenants = null;
        $this->resolvedManagedTenants = null;
        $this->resolvedForUserId = null;
        $this->clients = [];
    }

    /** @return array<string, array<string, mixed>> */
    protected function activeTenants(): array
    {
        $this->ensureUserContext();

        return $this->resolvedActiveTenants ??= $this->loadTenants(onlyActive: true);
    }

    /** @return array<string, array<string, mixed>> */
    protected function managedTenants(): array
    {
        $this->ensureUserContext();

        return $this->resolvedManagedTenants ??= $this->loadTenants(onlyActive: false);
    }

    protected function build(string $key): FusionClient
    {
        $config = $this->activeTenants()[$key];

        return new FusionClient(
            baseUrl: (string) $config['base_url'],
            username: (string) $config['username'],
            password: (string) $config['password'],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadTenants(bool $onlyActive): array
    {
        $userId = $this->currentUserId();

        if ($userId === null
            || ! Schema::hasTable('oracle_tenants')
            || ! Schema::hasTable('auth_connections')) {
            return [];
        }

        $query = OracleTenant::query()
            ->where('user_id', $userId)
            ->with(['authConnections' => fn ($query) => $query
                ->orderByDesc('is_default')
                ->orderBy('id')])
            ->orderByDesc('is_default')
            ->orderBy('label');

        if ($onlyActive) {
            $query
                ->where('is_active', true)
                ->whereHas('authConnections', fn ($query) => $query
                    ->where('auth_type', 'basic')
                    ->where('is_active', true)
                    ->whereNotNull('verified_at'));
        }

        return $query
            ->get()
            ->mapWithKeys(function (OracleTenant $tenant) use ($onlyActive): array {
                /** @var AuthConnection|null $connection */
                $connection = $onlyActive
                    ? $tenant->authConnections->first(
                        fn (AuthConnection $connection): bool => $connection->is_active
                            && $connection->auth_type === 'basic'
                            && $connection->verified_at !== null,
                    )
                    : $tenant->authConnections->first();

                if ($connection === null) {
                    return [];
                }

                return [
                    $tenant->key => [
                        'id' => $tenant->id,
                        'label' => $tenant->label,
                        'base_url' => $tenant->base_url,
                        'username' => $connection->identifier,
                        'password' => $connection->secret,
                        'auth_type' => $connection->auth_type,
                        'connection_id' => $connection->id,
                        'connection_count' => $tenant->authConnections->count(),
                        'connection_is_active' => $connection->is_active,
                        'connection_is_supported' => $connection->auth_type === 'basic',
                        'verified_at' => $connection->verified_at,
                        'last_tested_at' => $connection->last_tested_at,
                        'is_default' => $tenant->is_default,
                        'is_active' => $tenant->is_active,
                    ],
                ];
            })
            ->all();
    }

    private function ensureUserContext(): void
    {
        $userId = $this->currentUserId();

        if ($this->resolvedForUserId === $userId) {
            return;
        }

        $this->resolvedActiveTenants = null;
        $this->resolvedManagedTenants = null;
        $this->clients = [];
        $this->resolvedForUserId = $userId;
    }

    private function currentUserId(): ?int
    {
        if ($this->explicitUserId !== null) {
            return $this->explicitUserId;
        }

        $user = Auth::user();

        return $user instanceof User ? $user->id : null;
    }
}
