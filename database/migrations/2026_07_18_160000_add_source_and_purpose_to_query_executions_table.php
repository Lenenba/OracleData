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
        Schema::table('query_executions', function (Blueprint $table) {
            $table->string('source_type', 32)
                ->default('saved_query')
                ->after('id');
            $table->foreignId('query_template_id')
                ->nullable()
                ->after('query_id')
                ->constrained('query_templates')
                ->nullOnDelete();
            $table->string('purpose', 16)
                ->default('run')
                ->after('query_template_id');

            $table->index(
                ['query_template_id', 'purpose', 'finished_at'],
                'query_executions_template_purpose_finished_index',
            );
            $table->index(
                ['user_id', 'purpose', 'finished_at'],
                'query_executions_user_purpose_finished_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table) {
            $table->dropIndex('query_executions_template_purpose_finished_index');
            $table->dropIndex('query_executions_user_purpose_finished_index');
            $table->dropConstrainedForeignId('query_template_id');
            $table->dropColumn(['source_type', 'purpose']);
        });
    }
};
