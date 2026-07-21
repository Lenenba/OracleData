<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 10A — adds the parameter_definitions column.
     *
     * The earlier stub migration (220131) ran as a no-op because it was
     * scaffolded before this implementation was complete. This migration
     * applies the actual DDL change.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('queries', 'parameter_definitions')) {
            Schema::table('queries', function (Blueprint $table): void {
                $table->json('parameter_definitions')->nullable()->after('parameters');
            });
        }
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropColumn('parameter_definitions');
        });
    }
};
