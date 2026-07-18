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
        Schema::table('queries', function (Blueprint $table) {
            $table->unsignedInteger('execution_count')->default(0);
            $table->unsignedInteger('successful_execution_count')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamp('last_successful_execution_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn([
                'execution_count',
                'successful_execution_count',
                'last_executed_at',
                'last_successful_execution_at',
            ]);
        });
    }
};
