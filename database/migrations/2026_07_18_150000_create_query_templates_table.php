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
        Schema::create('query_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_key', 100);
            $table->string('resource_path');
            $table->json('parameters');
            $table->json('parameter_definitions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['category_id', 'is_active']);
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->foreignId('query_template_id')
                ->nullable()
                ->after('category_id')
                ->constrained('query_templates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('query_template_id');
        });

        Schema::dropIfExists('query_templates');
    }
};
