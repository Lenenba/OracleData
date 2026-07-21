<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 12B/12C — Tokens d'API personnels avec scopes et quotas.
 *
 * Un token est un secret HMAC à 64 hex chars stocké hashé (SHA-256).
 * Le plain-text n'est affiché qu'une seule fois à la création.
 * Les scopes sont une liste JSON de chaînes parmi : read:queries, run:queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained(indexName: 'personal_api_tokens_user_fk')
                ->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->json('scopes');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('requests_today')->default(0);
            $table->unsignedInteger('daily_limit')->default(1000);
            $table->date('requests_today_date')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'personal_api_tokens_user_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_api_tokens');
    }
};
