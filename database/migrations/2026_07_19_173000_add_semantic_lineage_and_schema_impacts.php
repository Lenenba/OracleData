<?php

use App\Enums\OracleSchemaImpactStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_semantic_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semantic_resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semantic_catalog_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('resource_key', 100);
            $table->string('child_key', 100)->default('');
            $table->string('field_key', 150)->default('');
            $table->string('usage', 32);
            $table->char('definition_hash', 64)->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['query_id', 'resource_key', 'child_key', 'field_key', 'usage'],
                'query_semantic_resources_dependency_unique',
            );
            $table->index(
                ['resource_key', 'child_key', 'field_key'],
                'query_semantic_resources_impact_lookup_index',
            );
        });

        Schema::create('query_template_version_semantic_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_template_version_id')
                ->constrained(indexName: 'qtv_semantic_resources_version_fk')
                ->cascadeOnDelete();
            $table->foreignId('semantic_resource_id')
                ->nullable()
                ->constrained(indexName: 'qtv_semantic_resources_resource_fk')
                ->nullOnDelete();
            $table->foreignId('semantic_catalog_version_id')
                ->nullable()
                ->constrained(indexName: 'qtv_semantic_resources_catalog_version_fk')
                ->restrictOnDelete();
            $table->string('resource_key', 100);
            $table->string('child_key', 100)->default('');
            $table->string('field_key', 150)->default('');
            $table->string('usage', 32);
            $table->char('definition_hash', 64)->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['query_template_version_id', 'resource_key', 'child_key', 'field_key', 'usage'],
                'query_template_semantic_resources_dependency_unique',
            );
            $table->index(
                ['resource_key', 'child_key', 'field_key'],
                'query_template_semantic_resources_impact_lookup_index',
            );
        });

        Schema::table('query_executions', function (Blueprint $table) {
            $table->foreignId('semantic_catalog_version_id')
                ->nullable()
                ->after('query_template_version_id')
                ->constrained(indexName: 'query_executions_semantic_catalog_version_fk')
                ->restrictOnDelete();
            $table->json('semantic_lineage')->nullable()->after('semantic_catalog_version_id');

            $table->index(
                ['semantic_catalog_version_id', 'finished_at'],
                'query_executions_semantic_catalog_index',
            );
        });

        Schema::table('oracle_resource_schema_snapshots', function (Blueprint $table) {
            $table->foreignId('semantic_catalog_version_id')
                ->nullable()
                ->after('triggered_by_user_id')
                ->constrained(indexName: 'oracle_schema_snapshots_catalog_version_fk')
                ->restrictOnDelete();
        });

        Schema::create('oracle_schema_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oracle_resource_schema_snapshot_id')
                ->constrained(indexName: 'oracle_schema_impacts_snapshot_fk')
                ->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semantic_catalog_version_id')
                ->nullable()
                ->constrained(indexName: 'oracle_schema_impacts_catalog_version_fk')
                ->restrictOnDelete();
            $table->foreignId('query_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('query_template_version_id')
                ->nullable()
                ->constrained(indexName: 'oracle_schema_impacts_template_version_fk')
                ->nullOnDelete();
            $table->string('resource_key', 100);
            $table->string('child_key', 100)->default('');
            $table->string('kind', 32);
            $table->string('severity', 32);
            $table->string('status', 32)->default(OracleSchemaImpactStatus::Open->value);
            $table->json('affected_fields');
            $table->char('fingerprint', 64)->unique();
            $table->timestamp('detected_at');
            $table->foreignId('acknowledged_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'oracle_schema_impacts_acknowledged_by_fk')
                ->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'oracle_schema_impacts_resolved_by_fk')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(
                ['oracle_tenant_id', 'resource_key', 'status'],
                'oracle_schema_impacts_tenant_resource_index',
            );
            $table->index(
                ['status', 'severity', 'detected_at'],
                'oracle_schema_impacts_workflow_index',
            );
            $table->index(
                ['query_id', 'status'],
                'oracle_schema_impacts_query_index',
            );
            $table->index(
                ['query_template_version_id', 'status'],
                'oracle_schema_impacts_template_version_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_schema_impacts');

        Schema::table('oracle_resource_schema_snapshots', function (Blueprint $table) {
            $table->dropForeign('oracle_schema_snapshots_catalog_version_fk');
            $table->dropColumn('semantic_catalog_version_id');
        });

        Schema::table('query_executions', function (Blueprint $table) {
            $table->dropIndex('query_executions_semantic_catalog_index');
            $table->dropForeign('query_executions_semantic_catalog_version_fk');
            $table->dropColumn(['semantic_catalog_version_id', 'semantic_lineage']);
        });

        Schema::dropIfExists('query_template_version_semantic_resources');
        Schema::dropIfExists('query_semantic_resources');
    }
};
