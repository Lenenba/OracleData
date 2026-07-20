<?php

use App\Enums\QueryExportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained(indexName: 'query_exports_user_fk')
                ->cascadeOnDelete();
            $table->foreignId('query_id')
                ->constrained(indexName: 'query_exports_query_fk')
                ->cascadeOnDelete();
            $table->foreignId('oracle_tenant_id')
                ->nullable()
                ->constrained(indexName: 'query_exports_tenant_fk')
                ->nullOnDelete();
            $table->foreignId('auth_connection_id')
                ->nullable()
                ->constrained(indexName: 'query_exports_connection_fk')
                ->nullOnDelete();
            $table->string('status', 20)->default(QueryExportStatus::Queued->value);
            $table->string('format', 10)->default('csv');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('max_rows');
            $table->boolean('truncated')->default(false);
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'id'], 'query_exports_user_status_index');
            $table->index(['query_id', 'id'], 'query_exports_query_index');
            $table->index('expires_at', 'query_exports_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_exports');
    }
};
