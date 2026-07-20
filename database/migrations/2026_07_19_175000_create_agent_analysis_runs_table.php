<?php

use App\Enums\AgentAnalysisRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained(indexName: 'agent_analysis_runs_user_fk')
                ->cascadeOnDelete();
            $table->foreignId('query_id')
                ->constrained(indexName: 'agent_analysis_runs_query_fk')
                ->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_tenant_fk')
                ->nullOnDelete();
            $table->foreignId('auth_connection_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_connection_fk')
                ->nullOnDelete();
            $table->foreignId('query_execution_id')
                ->nullable()
                ->constrained(indexName: 'agent_analysis_runs_execution_fk')
                ->nullOnDelete();
            $table->string('status', 20)->default(AgentAnalysisRunStatus::Queued->value);
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

            $table->index(['user_id', 'status', 'id'], 'agent_analysis_runs_user_status_index');
            $table->index(['query_id', 'id'], 'agent_analysis_runs_query_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_analysis_runs');
    }
};
