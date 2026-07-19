<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oracle_resource_fields', function (Blueprint $table) {
            $table->string('source', 32)->default('probe')->after('fields');
            $table->string('title')->nullable()->after('source');
            $table->json('attributes')->nullable()->after('title');
            $table->string('schema_hash', 64)->nullable()->after('attributes')->index();
        });

        Schema::create('oracle_resource_schema_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oracle_tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_snapshot_id')
                ->nullable()
                ->constrained('oracle_resource_schema_snapshots')
                ->nullOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resource_key');
            $table->string('child')->default('');
            $table->string('source', 32);
            $table->string('title')->nullable();
            $table->json('fields');
            $table->json('attributes');
            $table->string('schema_hash', 64);
            $table->json('diff');
            $table->timestamp('synced_at');

            $table->index(
                ['oracle_tenant_id', 'resource_key', 'child', 'synced_at'],
                'oracle_schema_snapshots_lookup_index',
            );
            $table->index('schema_hash', 'oracle_schema_snapshots_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_resource_schema_snapshots');

        Schema::table('oracle_resource_fields', function (Blueprint $table) {
            $table->dropIndex(['schema_hash']);
            $table->dropColumn(['source', 'title', 'attributes', 'schema_hash']);
        });
    }
};
