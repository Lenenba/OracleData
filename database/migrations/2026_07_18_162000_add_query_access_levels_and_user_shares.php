<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropIndex('queries_visibility_updated_index');
        });

        DB::table('queries')
            ->where('visibility', 'shared')
            ->update(['visibility' => 'organization']);

        Schema::table('queries', function (Blueprint $table) {
            $table->renameColumn('visibility', 'access_level');
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->index(
                ['access_level', 'updated_at'],
                'queries_access_level_updated_index',
            );
        });

        Schema::create('query_user_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('permission', ['view', 'execute', 'clone', 'manage']);
            $table->enum('status', ['accepted', 'revoked'])->default('accepted');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['query_id', 'user_id'],
                'query_user_shares_query_recipient_unique',
            );
            $table->index(
                ['user_id', 'status', 'expires_at'],
                'query_user_shares_recipient_active_index',
            );
            $table->index(
                ['query_id', 'status'],
                'query_user_shares_query_status_index',
            );
            $table->index(
                ['shared_by_user_id', 'status'],
                'query_user_shares_granter_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_user_shares');

        Schema::table('queries', function (Blueprint $table) {
            $table->dropIndex('queries_access_level_updated_index');
        });

        DB::table('queries')
            ->where('access_level', 'organization')
            ->update(['access_level' => 'shared']);
        DB::table('queries')
            ->where('access_level', 'restricted')
            ->update(['access_level' => 'private']);

        Schema::table('queries', function (Blueprint $table) {
            $table->renameColumn('access_level', 'visibility');
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->index(
                ['visibility', 'updated_at'],
                'queries_visibility_updated_index',
            );
        });
    }
};
