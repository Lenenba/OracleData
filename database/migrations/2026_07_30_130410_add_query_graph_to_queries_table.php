<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la colonne query_graph (JSON nullable) à la table queries.
 *
 * Sémantique :
 *   - NULL   → requête simple (resource_path + parameters existants)
 *   - non-NULL → requête hiérarchique (QueryGraph sérialisé)
 *
 * La rétrocompatibilité est garantie : toutes les requêtes existantes
 * gardent query_graph = NULL et continuent de fonctionner comme avant.
 *
 * Format JSON stocké :
 *   {
 *     "version": 1,
 *     "maxDepth": 6,
 *     "root": { "nodeId": "...", "resourceId": "hcm.workers", "depth": 1, ... }
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->json('query_graph')->nullable()->after('parameters');
        });
    }

    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropColumn('query_graph');
        });
    }
};
