<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'name'], 'groups_owner_name_index');
        });

        Schema::create('group_user', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'manager', 'member'])->default('member');
            $table->timestamps();

            $table->unique(['group_id', 'user_id'], 'group_user_membership_unique');
            $table->index(['user_id', 'role'], 'group_user_user_role_index');
            $table->index(['group_id', 'role'], 'group_user_group_role_index');
        });

        Schema::create('query_group_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->restrictOnDelete();
            $table->string('group_name', 100);
            $table->foreignId('shared_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->enum('permission', ['view', 'execute', 'clone']);
            $table->enum('status', ['accepted', 'revoked'])->default('accepted');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['query_id', 'group_id', 'status'],
                'query_group_shares_query_group_status_index',
            );
            $table->index(
                ['group_id', 'status', 'expires_at'],
                'query_group_shares_group_active_index',
            );
            $table->index(
                ['query_id', 'status'],
                'query_group_shares_query_status_index',
            );
            $table->index(
                ['shared_by_user_id', 'status'],
                'query_group_shares_granter_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_group_shares');
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
    }
};
