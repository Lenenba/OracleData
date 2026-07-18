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
        Schema::create('oracle_resource_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oracle_tenant_id')->constrained()->cascadeOnDelete();
            $table->string('resource_key');
            // Enfant expand ('' pour la ressource racine) : garde l'index unique
            // fiable, les NULL étant considérés distincts par SQLite/MySQL.
            $table->string('child')->default('');
            $table->json('fields');
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['oracle_tenant_id', 'resource_key', 'child'], 'oracle_resource_fields_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oracle_resource_fields');
    }
};
