<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 10D — analytical exports.
     *
     * The `format` column already exists on `query_exports` with a default of
     * 'csv'. This migration widens the allowed values to 'xlsx' and 'json' and
     * adds an `export_options` JSON column for format-specific settings such as
     * the sheet name (XLSX) or pretty-printing (JSON).
     *
     * The existing `format` column is left in place; only its default and
     * comment are documented here — no DDL change is needed for the enum values
     * because SQLite / MySQL store the column as a plain varchar.
     */
    public function up(): void
    {
        Schema::table('query_exports', function (Blueprint $table): void {
            $table->json('export_options')->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('query_exports', function (Blueprint $table): void {
            $table->dropColumn('export_options');
        });
    }
};
