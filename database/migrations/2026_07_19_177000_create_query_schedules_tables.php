<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained(indexName: 'query_schedules_user_fk')
                ->cascadeOnDelete();
            $table->foreignId('query_id')
                ->constrained(indexName: 'query_schedules_query_fk')
                ->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'query_schedules_tenant_fk')
                ->nullOnDelete();
            $table->string('name');
            $table->string('tenant_key');
            $table->string('frequency', 10);
            $table->string('time_of_day', 5)->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at'], 'query_schedules_due_index');
            $table->index(['user_id', 'id'], 'query_schedules_owner_index');
        });

        Schema::create('query_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_schedule_id')
                ->constrained(indexName: 'query_schedule_runs_schedule_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(indexName: 'query_schedule_runs_user_fk')
                ->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'query_schedule_runs_tenant_fk')
                ->nullOnDelete();
            $table->foreignId('auth_connection_id')
                ->nullable()
                ->constrained(indexName: 'query_schedule_runs_connection_fk')
                ->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['query_schedule_id', 'ran_at'], 'query_schedule_runs_schedule_ran_index');
            $table->index(['user_id', 'ran_at'], 'query_schedule_runs_owner_ran_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_schedule_runs');
        Schema::dropIfExists('query_schedules');
    }
};
