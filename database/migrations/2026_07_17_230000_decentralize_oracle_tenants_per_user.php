<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the legacy global Oracle credentials to user-owned environments and
     * authentication connections.
     */
    public function up(): void
    {
        // Stop before any schema mutation when a legacy installation contains
        // global tenants but no unambiguous owner. Silently assigning those
        // credentials to an arbitrary account would break tenant isolation.
        $legacyTenantCount = DB::table('oracle_tenants')->count();
        $legacyOwnerId = DB::table('users')
            ->where('is_super_admin', true)
            ->orderBy('id')
            ->value('id');

        if ($legacyOwnerId === null && DB::table('users')->count() === 1) {
            $legacyOwnerId = DB::table('users')->value('id');
        }

        if ($legacyTenantCount > 0 && $legacyOwnerId === null) {
            throw new RuntimeException(
                'Cannot migrate global Oracle tenants without an explicit owner. '
                .'Grant one existing user the super-admin role, then retry the migration.',
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('timezone');
        });

        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        // Legacy tenants were already administered by super-administrators. To
        // preserve the local installation without granting them to every user,
        // assign them to the first existing super-admin (or the sole user).
        if ($legacyOwnerId !== null) {
            DB::table('oracle_tenants')
                ->whereNull('user_id')
                ->update(['user_id' => $legacyOwnerId]);
        }

        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->dropUnique('oracle_tenants_key_unique');
            $table->unique(['user_id', 'key']);
            $table->unique(['user_id', 'id']);
            $table->index(['user_id', 'is_active', 'is_default']);
        });

        Schema::create('auth_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('auth_type', 32)->default('basic');
            $table->string('identifier');
            $table->text('secret');
            $table->text('configuration')->nullable();
            $table->boolean('is_default')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_test_succeeded_at')->nullable();
            $table->timestamps();

            $table->unique(['oracle_tenant_id', 'name']);
            $table->index(['user_id', 'is_active']);
            $table->foreign(['user_id', 'oracle_tenant_id'])
                ->references(['user_id', 'id'])
                ->on('oracle_tenants')
                ->cascadeOnDelete();
        });

        $legacyTenants = DB::table('oracle_tenants')
            ->select([
                'id',
                'user_id',
                'label',
                'username',
                'password',
                'is_default',
                'is_active',
                'created_at',
                'updated_at',
            ])
            ->get();

        foreach ($legacyTenants as $tenant) {
            DB::table('auth_connections')->insert([
                'user_id' => $tenant->user_id,
                'oracle_tenant_id' => $tenant->id,
                'name' => $tenant->label,
                'auth_type' => 'basic',
                'identifier' => $tenant->username,
                // The existing value is already encrypted by the model cast.
                'secret' => $tenant->password,
                'configuration' => null,
                'is_default' => true,
                'is_active' => $tenant->is_active,
                'verified_at' => null,
                'last_tested_at' => null,
                'last_test_succeeded_at' => null,
                'created_at' => $tenant->created_at,
                'updated_at' => $tenant->updated_at,
            ]);
        }

        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->dropColumn(['username', 'password']);
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->after('tenant_key')
                ->constrained('oracle_tenants')
                ->nullOnDelete();
            $table->index(['user_id', 'oracle_tenant_id']);
        });

        DB::table('queries')
            ->select(['id', 'user_id', 'tenant_key'])
            ->whereNotNull('tenant_key')
            ->orderBy('id')
            ->eachById(function (object $query): void {
                $tenantId = DB::table('oracle_tenants')
                    ->where('user_id', $query->user_id)
                    ->where('key', $query->tenant_key)
                    ->value('id');

                if ($tenantId !== null) {
                    DB::table('queries')
                        ->where('id', $query->id)
                        ->update(['oracle_tenant_id' => $tenantId]);
                }
            });

        DB::table('users')
            ->whereNull('onboarding_completed_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('auth_connections')
                    ->whereColumn('auth_connections.user_id', 'users.id')
                    ->where('auth_connections.is_active', true);
            })
            ->update(['onboarding_completed_at' => now()]);
    }

    /**
     * Restore the legacy layout when no per-user key collision was introduced.
     */
    public function down(): void
    {
        $hasDuplicateKeys = DB::table('oracle_tenants')
            ->select('key')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateKeys) {
            throw new RuntimeException(
                'Cannot roll back per-user Oracle tenants while duplicate tenant keys exist.',
            );
        }

        Schema::table('queries', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'oracle_tenant_id']);
            $table->dropConstrainedForeignId('oracle_tenant_id');
        });

        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->string('username')->nullable()->after('base_url');
            $table->text('password')->nullable()->after('username');
        });

        DB::table('oracle_tenants')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $tenant): void {
                $connection = DB::table('auth_connections')
                    ->where('oracle_tenant_id', $tenant->id)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->first();

                if ($connection === null) {
                    throw new RuntimeException(
                        "Cannot roll back Oracle tenant [{$tenant->id}] without an authentication connection.",
                    );
                }

                DB::table('oracle_tenants')
                    ->where('id', $tenant->id)
                    ->update([
                        'username' => $connection->identifier,
                        'password' => $connection->secret,
                    ]);
            });

        Schema::dropIfExists('auth_connections');

        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active', 'is_default']);
            $table->dropUnique(['user_id', 'key']);
            $table->dropUnique(['user_id', 'id']);
            $table->unique('key');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
