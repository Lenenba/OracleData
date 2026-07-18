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
        Schema::create('query_template_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_template_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('parameter_labels')->nullable();
            $table->json('parameter_descriptions')->nullable();
            $table->json('parameter_options')->nullable();
            $table->timestamps();

            $table->unique(
                ['query_template_id', 'locale'],
                'query_template_translations_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_template_translations');
    }
};
