<?php

use App\Enums\SemanticCatalogVersionStatus;
use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Enums\SemanticRelationStatus;
use App\Enums\SemanticSqlMappingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semantic_resources', function (Blueprint $table) {
            $table->id();
            $table->string('resource_key', 100)->unique();
            $table->string('source_name', 150);
            $table->string('domain', 100)->index();
            $table->string('api_path');
            $table->foreignId('business_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('technical_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('classification', 32)->default(SemanticClassification::Unclassified->value);
            $table->string('data_category', 32)->default(SemanticDataCategory::General->value);
            $table->string('sql_table', 128)->nullable();
            $table->string('sql_alias', 30)->nullable();
            $table->string('sql_mapping_status', 32)->default(SemanticSqlMappingStatus::Unmapped->value);
            $table->text('mapping_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['is_active', 'domain'], 'semantic_resources_library_index');
            $table->index(
                ['classification', 'data_category'],
                'semantic_resources_classification_index',
            );
            $table->index(
                ['technical_owner_user_id', 'is_active'],
                'semantic_resources_technical_owner_index',
            );
        });

        Schema::create('semantic_resource_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semantic_resource_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('synonyms')->nullable();
            $table->json('examples')->nullable();
            $table->timestamps();

            $table->unique(
                ['semantic_resource_id', 'locale'],
                'semantic_resource_translations_unique',
            );
            $table->index(['locale', 'name'], 'semantic_resource_translations_search_index');
        });

        Schema::create('semantic_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semantic_resource_id')->constrained()->cascadeOnDelete();
            $table->string('child_key', 100)->default('');
            $table->string('source_name', 150);
            $table->string('data_type', 64)->nullable();
            $table->string('classification', 32)->default(SemanticClassification::Unclassified->value);
            $table->string('data_category', 32)->default(SemanticDataCategory::General->value);
            $table->text('sql_expression')->nullable();
            $table->string('sql_mapping_status', 32)->default(SemanticSqlMappingStatus::Unmapped->value);
            $table->boolean('is_nullable')->nullable();
            $table->boolean('is_updatable')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(
                ['semantic_resource_id', 'child_key', 'source_name'],
                'semantic_fields_source_unique',
            );
            $table->index(
                ['semantic_resource_id', 'child_key', 'is_active'],
                'semantic_fields_resource_lookup_index',
            );
            $table->index(
                ['classification', 'data_category'],
                'semantic_fields_classification_index',
            );
        });

        Schema::create('semantic_field_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semantic_field_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('synonyms')->nullable();
            $table->json('examples')->nullable();
            $table->timestamps();

            $table->unique(
                ['semantic_field_id', 'locale'],
                'semantic_field_translations_unique',
            );
            $table->index(['locale', 'name'], 'semantic_field_translations_search_index');
        });

        Schema::create('semantic_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_resource_id')->nullable()->constrained('semantic_resources')->nullOnDelete();
            $table->foreignId('target_resource_id')->nullable()->constrained('semantic_resources')->nullOnDelete();
            $table->string('relation_key', 150)->unique();
            $table->string('kind', 32);
            $table->string('target_key', 100);
            $table->string('source_field', 150)->nullable();
            $table->string('target_field', 150)->nullable();
            $table->string('cardinality', 32);
            $table->string('sql_table', 128)->nullable();
            $table->string('sql_alias', 30)->nullable();
            $table->text('sql_join')->nullable();
            $table->string('status', 32)->default(SemanticRelationStatus::Draft->value);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(
                ['source_resource_id', 'is_active'],
                'semantic_relations_source_index',
            );
            $table->index(
                ['target_resource_id', 'is_active'],
                'semantic_relations_target_index',
            );
            $table->index(['status', 'is_active'], 'semantic_relations_status_index');
        });

        Schema::create('semantic_relation_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semantic_relation_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(
                ['semantic_relation_id', 'locale'],
                'semantic_relation_translations_unique',
            );
        });

        Schema::create('semantic_glossary_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term_key', 120)->unique();
            $table->string('domain', 100)->nullable()->index();
            $table->string('classification', 32)->default(SemanticClassification::Unclassified->value);
            $table->string('data_category', 32)->default(SemanticDataCategory::General->value);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['is_active', 'domain'], 'semantic_glossary_terms_library_index');
        });

        Schema::create('semantic_glossary_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semantic_glossary_term_id')
                ->constrained(indexName: 'semantic_glossary_translations_term_fk')
                ->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('term');
            $table->text('definition');
            $table->json('synonyms')->nullable();
            $table->json('forbidden_terms')->nullable();
            $table->json('examples')->nullable();
            $table->timestamps();

            $table->unique(
                ['semantic_glossary_term_id', 'locale'],
                'semantic_glossary_translations_unique',
            );
            $table->index(['locale', 'term'], 'semantic_glossary_translations_search_index');
        });

        Schema::create('semantic_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version_number')->unique();
            $table->string('status', 32)->default(SemanticCatalogVersionStatus::Draft->value);
            $table->unsignedTinyInteger('open_slot')->nullable()->unique();
            $table->unsignedTinyInteger('published_slot')->nullable()->unique();
            $table->json('catalog');
            $table->char('content_hash', 64)->index();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['status', 'published_at'], 'semantic_catalog_versions_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semantic_catalog_versions');
        Schema::dropIfExists('semantic_glossary_translations');
        Schema::dropIfExists('semantic_glossary_terms');
        Schema::dropIfExists('semantic_relation_translations');
        Schema::dropIfExists('semantic_relations');
        Schema::dropIfExists('semantic_field_translations');
        Schema::dropIfExists('semantic_fields');
        Schema::dropIfExists('semantic_resource_translations');
        Schema::dropIfExists('semantic_resources');
    }
};
