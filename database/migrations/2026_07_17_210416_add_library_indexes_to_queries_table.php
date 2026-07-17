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
            $table->index(['user_id', 'updated_at'], 'queries_user_updated_index');
            $table->index(['visibility', 'updated_at'], 'queries_visibility_updated_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropIndex('queries_user_updated_index');
            $table->dropIndex('queries_visibility_updated_index');
        });
    }
};
