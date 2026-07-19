<?php

use App\Enums\DataQualityHealthStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('query_template_versions', function (Blueprint $table) {
            $table->json('quality_rules')->nullable()->after('translations');
        });

        Schema::table('query_templates', function (Blueprint $table) {
            $table->string('quality_status', 20)
                ->default(DataQualityHealthStatus::Unknown->value)
                ->after('lock_version');
            $table->decimal('quality_score', 5, 2)->nullable()->after('quality_status');
            $table->timestamp('quality_checked_at')->nullable()->after('quality_score');
            $table->unsignedInteger('quality_failure_streak')
                ->default(0)
                ->after('quality_checked_at');

            $table->index(
                ['quality_status', 'quality_checked_at'],
                'query_templates_quality_health_index',
            );
        });

        Schema::create('query_template_reference_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_template_id')
                ->constrained(indexName: 'qt_reference_datasets_template_fk')
                ->restrictOnDelete();
            $table->foreignId('query_template_version_id')
                ->constrained(indexName: 'qt_reference_datasets_version_fk')
                ->restrictOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'qt_reference_datasets_tenant_fk')
                ->nullOnDelete();
            $table->foreignId('auth_connection_id')
                ->nullable()
                ->constrained(indexName: 'qt_reference_datasets_connection_fk')
                ->nullOnDelete();
            $table->foreignId('captured_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'qt_reference_datasets_author_fk')
                ->nullOnDelete();
            $table->string('name');
            $table->string('scenario', 24);
            $table->char('parameter_hash', 64);
            $table->json('parameter_keys');
            $table->json('comparison_config');
            $table->json('fingerprint');
            $table->unsignedInteger('row_count')->default(0);
            $table->char('dataset_hash', 64);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(
                ['query_template_id', 'captured_at'],
                'qt_reference_datasets_template_captured_index',
            );
            $table->index(
                ['query_template_version_id', 'scenario', 'captured_at'],
                'qt_reference_datasets_version_scenario_index',
            );
            $table->index(
                ['oracle_tenant_id', 'captured_at'],
                'qt_reference_datasets_tenant_captured_index',
            );
            $table->index(
                ['query_template_version_id', 'parameter_hash', 'dataset_hash'],
                'qt_reference_datasets_hash_lookup_index',
            );
        });

        Schema::create('query_template_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_template_id')
                ->constrained(indexName: 'qt_validation_runs_template_fk')
                ->restrictOnDelete();
            $table->foreignId('query_template_version_id')
                ->constrained(indexName: 'qt_validation_runs_version_fk')
                ->restrictOnDelete();
            $table->foreignId('query_execution_id')
                ->nullable()
                ->constrained(indexName: 'qt_validation_runs_execution_fk')
                ->nullOnDelete();
            $table->foreignId('query_template_reference_dataset_id')
                ->nullable()
                ->constrained(indexName: 'qt_validation_runs_reference_fk')
                ->restrictOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'qt_validation_runs_tenant_fk')
                ->nullOnDelete();
            $table->foreignId('auth_connection_id')
                ->nullable()
                ->constrained(indexName: 'qt_validation_runs_connection_fk')
                ->nullOnDelete();
            $table->foreignId('run_by_user_id')
                ->nullable()
                ->constrained('users', indexName: 'qt_validation_runs_author_fk')
                ->nullOnDelete();
            $table->string('purpose', 24);
            $table->string('status', 20);
            $table->char('version_content_hash', 64);
            $table->char('rules_hash', 64);
            $table->char('parameter_hash', 64);
            $table->json('assertion_results')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(
                ['query_template_id', 'status', 'finished_at'],
                'qt_validation_runs_template_status_index',
            );
            $table->index(
                ['query_template_version_id', 'purpose', 'started_at'],
                'qt_validation_runs_version_purpose_index',
            );
            $table->index(
                ['query_template_reference_dataset_id', 'started_at'],
                'qt_validation_runs_reference_started_index',
            );
            $table->index(
                ['oracle_tenant_id', 'status', 'started_at'],
                'qt_validation_runs_tenant_status_index',
            );
        });

        Schema::table('query_templates', function (Blueprint $table) {
            $table->foreignId('latest_quality_run_id')
                ->nullable()
                ->after('quality_failure_streak')
                ->constrained(
                    'query_template_validation_runs',
                    indexName: 'query_templates_latest_quality_run_fk',
                )
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('query_templates', function (Blueprint $table) {
            $table->dropIndex('query_templates_quality_health_index');
            $table->dropForeign('query_templates_latest_quality_run_fk');
            $table->dropColumn([
                'quality_status',
                'quality_score',
                'quality_checked_at',
                'quality_failure_streak',
                'latest_quality_run_id',
            ]);
        });

        Schema::dropIfExists('query_template_validation_runs');
        Schema::dropIfExists('query_template_reference_datasets');

        Schema::table('query_template_versions', function (Blueprint $table) {
            $table->dropColumn('quality_rules');
        });
    }
};
