<?php

namespace App\Services;

use App\Models\AuthConnection;
use App\Models\OracleTenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the transactional lifecycle of a user's Oracle environment and its
 * primary Basic Auth connection.
 */
class TenantConnectionService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, bool $completeOnboarding = false): OracleTenant
    {
        $testedAt = now();

        if (! $this->testCredentials($data['base_url'], $data['username'], $data['password'])) {
            throw ValidationException::withMessages([
                'connection' => __("Impossible de valider cette connexion Oracle. Vérifiez l'URL et les identifiants."),
            ]);
        }

        return DB::transaction(function () use ($user, $data, $completeOnboarding, $testedAt): OracleTenant {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($completeOnboarding && $owner->hasCompletedOnboarding()) {
                $existingTenant = $owner->oracleTenants()
                    ->where('is_active', true)
                    ->whereHas('authConnections', fn ($query) => $query
                        ->where('auth_type', 'basic')
                        ->where('is_active', true)
                        ->whereNotNull('verified_at'))
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->first();

                if ($existingTenant !== null) {
                    return $existingTenant->load('authConnections');
                }
            }

            $makeDefault = $completeOnboarding
                || (bool) ($data['is_default'] ?? false)
                || $this->activeTenantCount($owner) === 0;

            if ($makeDefault) {
                $owner->oracleTenants()->update(['is_default' => false]);
            }

            $tenant = $owner->oracleTenants()->create([
                'key' => $data['key'],
                'label' => $data['label'],
                'base_url' => rtrim($data['base_url'], '/'),
                'is_default' => $makeDefault,
                'is_active' => true,
            ]);

            $tenant->authConnections()->create([
                'user_id' => $owner->id,
                'name' => $data['label'],
                'auth_type' => 'basic',
                'identifier' => $data['username'],
                'secret' => $data['password'],
                'configuration' => null,
                'is_default' => true,
                'is_active' => true,
                'verified_at' => $testedAt,
                'last_tested_at' => $testedAt,
                'last_test_succeeded_at' => $testedAt,
            ]);

            if ($completeOnboarding && ! $owner->hasCompletedOnboarding()) {
                $owner->forceFill(['onboarding_completed_at' => $testedAt])->save();
            }

            return $tenant->load('authConnections');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, OracleTenant $tenant, array $data): OracleTenant
    {
        $this->assertOwnership($user, $tenant);

        $connection = $this->primaryConnection($tenant);
        $secret = filled($data['password'] ?? null)
            ? (string) $data['password']
            : $connection->secret;
        $isActive = (bool) ($data['is_active'] ?? true);
        $credentialsChanged = rtrim((string) $data['base_url'], '/') !== $tenant->base_url
            || (string) $data['username'] !== $connection->identifier
            || filled($data['password'] ?? null);

        $testedAt = null;
        $requiresTest = $isActive
            && (! $connection->is_active || $credentialsChanged || $connection->verified_at === null);

        if ($requiresTest && ! $this->testCredentials($data['base_url'], $data['username'], $secret)) {
            throw ValidationException::withMessages([
                'connection' => __("Impossible de valider cette connexion Oracle. Les modifications n'ont pas été enregistrées."),
            ]);
        }

        if ($requiresTest) {
            $testedAt = now();
        }

        return DB::transaction(function () use ($user, $tenant, $connection, $data, $secret, $isActive, $credentialsChanged, $testedAt): OracleTenant {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $tenant->refresh();

            if (! $isActive && $this->isUsableTenant($tenant) && $this->activeTenantCount($owner) <= 1) {
                throw ValidationException::withMessages([
                    'is_active' => __('Vous devez conserver au moins une connexion Oracle active.'),
                ]);
            }

            $makeDefault = $isActive && (bool) ($data['is_default'] ?? false);
            $wasDefault = $tenant->is_default;

            if ($makeDefault) {
                $owner->oracleTenants()
                    ->whereKeyNot($tenant->id)
                    ->update(['is_default' => false]);
            }

            $tenant->update([
                'label' => $data['label'],
                'base_url' => rtrim($data['base_url'], '/'),
                'is_default' => $makeDefault,
                'is_active' => $isActive,
            ]);

            $connectionData = [
                'name' => $data['label'],
                'identifier' => $data['username'],
                'secret' => $secret,
                'is_active' => $isActive,
            ];

            if ($testedAt !== null) {
                $connectionData = [
                    ...$connectionData,
                    'verified_at' => $testedAt,
                    'last_tested_at' => $testedAt,
                    'last_test_succeeded_at' => $testedAt,
                ];
            } elseif (! $isActive && $credentialsChanged) {
                $connectionData['verified_at'] = null;
            }

            $connection->update($connectionData);

            if ($wasDefault && ! $makeDefault) {
                $replacement = $owner->oracleTenants()
                    ->whereKeyNot($tenant->id)
                    ->where('is_active', true)
                    ->whereHas('authConnections', fn ($query) => $query
                        ->where('auth_type', 'basic')
                        ->where('is_active', true)
                        ->whereNotNull('verified_at'))
                    ->orderBy('id')
                    ->first();

                if ($replacement !== null) {
                    $replacement->update(['is_default' => true]);
                } elseif ($isActive) {
                    $tenant->update(['is_default' => true]);
                }
            }

            return $tenant->refresh()->load('authConnections');
        });
    }

    public function delete(User $user, OracleTenant $tenant): void
    {
        $this->assertOwnership($user, $tenant);

        DB::transaction(function () use ($user, $tenant): void {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $tenant->refresh();

            if ($this->isUsableTenant($tenant) && $this->activeTenantCount($owner) <= 1) {
                throw ValidationException::withMessages([
                    'connection' => __('Vous devez conserver au moins une connexion Oracle active.'),
                ]);
            }

            $wasDefault = $tenant->is_default;
            $tenant->delete();

            if ($wasDefault) {
                $owner->oracleTenants()
                    ->where('is_active', true)
                    ->whereHas('authConnections', fn ($query) => $query
                        ->where('auth_type', 'basic')
                        ->where('is_active', true)
                        ->whereNotNull('verified_at'))
                    ->orderBy('id')
                    ->first()?->update(['is_default' => true]);
            }
        });
    }

    public function testCredentials(string $baseUrl, string $username, string $password): bool
    {
        return (new FusionClient(
            baseUrl: rtrim($baseUrl, '/'),
            username: $username,
            password: $password,
        ))->testConnection();
    }

    private function activeTenantCount(User $user): int
    {
        return $user->oracleTenants()
            ->where('is_active', true)
            ->whereHas('authConnections', fn ($query) => $query
                ->where('auth_type', 'basic')
                ->where('is_active', true)
                ->whereNotNull('verified_at'))
            ->count();
    }

    private function isUsableTenant(OracleTenant $tenant): bool
    {
        return $tenant->is_active
            && $tenant->authConnections()
                ->where('auth_type', 'basic')
                ->where('is_active', true)
                ->whereNotNull('verified_at')
                ->exists();
    }

    private function assertOwnership(User $user, OracleTenant $tenant): void
    {
        if ($tenant->user_id !== $user->id) {
            throw new AuthorizationException;
        }
    }

    private function primaryConnection(OracleTenant $tenant): AuthConnection
    {
        /** @var AuthConnection|null $connection */
        $connection = $tenant->authConnections()
            ->where('auth_type', 'basic')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'connection' => __('Cette connexion Oracle ne possède aucun mode d’authentification.'),
            ]);
        }

        return $connection;
    }
}
