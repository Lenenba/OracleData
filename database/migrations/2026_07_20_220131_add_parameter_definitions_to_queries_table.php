<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds an ordered JSON column to hold the runtime parameter definitions
     * for personal queries (lot 10A). Each element has the same shape as a
     * QueryTemplate parameter definition so the shared front-end components
     * can be reused without modification.
     */
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            // Nullable so that queries without parameters don't pay the cost
            // of serializing an empty array, and the migration is non-breaking
            // for all rows that already exist.
            $table->json('parameter_definitions')->nullable()->after('parameters');
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropColumn('parameter_definitions');
        });
    }
};
