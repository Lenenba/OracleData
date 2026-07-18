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
        Schema::create('query_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('auth_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('rows_count')->default(0);
            $table->string('error_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['query_id', 'finished_at'], 'query_executions_query_finished_index');
            $table->index(['user_id', 'finished_at'], 'query_executions_user_finished_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_executions');
    }
};
