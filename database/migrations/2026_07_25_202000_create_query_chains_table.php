<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaînage dynamique de requêtes par ID.
 *
 * Une "chaîne" relie une requête principale (primary) à une requête
 * secondaire (secondary). À l'exécution, les valeurs du champ
 * `extraction_field` extraites des items de la requête principale sont
 * injectées comme filtre dans la requête secondaire via le paramètre
 * `injection_param`.
 *
 * Le champ `injection_operator` détermine la forme du filtre Oracle REST :
 *  - "equals"  → PersonId = 42  (une seule valeur, première extraite)
 *  - "in"      → PersonId IN (1,2,3)  (toutes les valeurs distinctes)
 *
 * Contraintes de sécurité :
 *  - la chaîne appartient à l'utilisateur propriétaire de la requête principale ;
 *  - la requête secondaire doit être accessible au même utilisateur ;
 *  - max 5 chaînes par requête principale (évite les exécutions en cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_chains', function (Blueprint $table): void {
            $table->id();

            // Requête principale — les items de cette requête fournissent les IDs.
            $table->foreignId('primary_query_id')
                ->constrained('queries', indexName: 'query_chains_primary_fk')
                ->cascadeOnDelete();

            // Requête secondaire — recevra les IDs en filtre.
            $table->foreignId('secondary_query_id')
                ->constrained('queries', indexName: 'query_chains_secondary_fk')
                ->cascadeOnDelete();

            // Propriétaire de la chaîne (doit être propriétaire de la requête principale).
            $table->foreignId('user_id')
                ->constrained(indexName: 'query_chains_user_fk')
                ->cascadeOnDelete();

            // Champ extrait des items de la requête principale (ex : "PersonId").
            $table->string('extraction_field', 100);

            // Paramètre de filtre injecté dans la requête secondaire (ex : "PersonId").
            $table->string('injection_param', 100);

            // Opérateur Oracle REST utilisé pour l'injection.
            // "equals" → field = value  |  "in" → field IN (v1,v2,…)
            $table->string('injection_operator', 20)->default('in');

            // Libellé affiché dans l'UI (ex : "Détail des affectations").
            $table->string('label', 255)->nullable();

            // Ordre d'affichage parmi les chaînes d'une même requête principale.
            $table->unsignedTinyInteger('position')->default(0);

            $table->timestamps();

            // Une même paire (primary, secondary, extraction_field, injection_param)
            // ne peut être déclarée qu'une seule fois.
            $table->unique(
                ['primary_query_id', 'secondary_query_id', 'extraction_field', 'injection_param'],
                'query_chains_unique_binding',
            );

            $table->index(['primary_query_id', 'position'], 'query_chains_primary_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_chains');
    }
};
