<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 11E — rend query_id nullable sur agent_analysis_runs pour les
 * aperçus éphémères du builder (aucune requête sauvegardée requise).
 *
 * SQLite ne permet pas de modifier une colonne avec ALTER COLUMN :
 * on recrée la table avec la nouvelle définition.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support altering foreign key constraints.
        // We recreate the table with query_id nullable for SQLite, and use
        // a standard column change for other drivers.
        if (DB::getDriverName() === 'sqlite') {
            $this->recreateForSqlite();
        } else {
            Schema::table('agent_analysis_runs', function (Blueprint $table): void {
                $table->foreignId('query_id')
                    ->nullable()
                    ->change();
            });
        }
    }

    public function down(): void
    {
        // Reverting to non-nullable is intentionally left as a no-op because
        // existing ephemeral runs would violate the constraint.
    }

    private function recreateForSqlite(): void
    {
        // SQLite recreate: create a temp table, copy data, rename.
        Schema::create('agent_analysis_runs_new', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained(indexName: 'agent_analysis_runs_new_user_fk')
                ->cascadeOnDelete();
            $table->foreignId('query_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_new_query_fk')
                ->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_new_tenant_fk')
                ->nullOnDelete();
            $table->foreignId('auth_connection_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_new_connection_fk')
                ->nullOnDelete();
            $table->foreignId('query_execution_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_new_execution_fk')
                ->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('iteration')->default(0);
            $table->unsignedInteger('max_iterations')->default(8);
            $table->unsignedInteger('oracle_calls_count')->default(0);
            $table->json('result')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'id'], 'agent_analysis_runs_new_user_status_index');
            $table->index(['query_id', 'id'], 'agent_analysis_runs_new_query_index');
        });

        DB::statement('INSERT INTO agent_analysis_runs_new SELECT * FROM agent_analysis_runs');

        Schema::drop('agent_analysis_runs');
        Schema::rename('agent_analysis_runs_new', 'agent_analysis_runs');
    }
};
