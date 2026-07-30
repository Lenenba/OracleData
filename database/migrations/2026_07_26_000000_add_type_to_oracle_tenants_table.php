<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a `type` discriminator column to distinguish Oracle Fusion (ERP/HCM)
     * tenants from Oracle Integration Cloud (OIC) monitoring connections.
     *
     * Existing rows default to 'fusion' so the migration is non-destructive.
     */
    public function up(): void
    {
        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->string('type', 32)
                ->default('fusion')
                ->after('key')
                ->comment("'fusion' = ERP/HCM REST API, 'oic' = Oracle Integration Cloud monitoring");
        });
    }

    public function down(): void
    {
        Schema::table('oracle_tenants', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
