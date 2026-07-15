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
            // 'single' : requête mono-ressource (resource_path + parameters figés).
            // 'agent'  : analyse multi-ressources, ré-exécutée par l'agent LLM.
            $table->string('mode', 16)->default('single')->after('tenant_key');

            // Les requêtes 'agent' n'ont pas de chemin unique.
            $table->string('resource_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropColumn('mode');
            $table->string('resource_path')->nullable(false)->change();
        });
    }
};
