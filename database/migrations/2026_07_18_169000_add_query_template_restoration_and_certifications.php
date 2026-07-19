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
        Schema::table('query_template_versions', function (Blueprint $table) {
            $table->foreignId('restored_from_version_id')
                ->nullable()
                ->after('query_template_id')
                ->constrained('query_template_versions')
                ->nullOnDelete();

            $table->index(
                ['query_template_id', 'restored_from_version_id'],
                'query_template_versions_restoration_index',
            );
        });

        Schema::create('query_template_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('query_template_version_id')
                ->constrained('query_template_versions')
                ->restrictOnDelete();
            $table->char('version_content_hash', 64);
            // As for open_slot on versions, NULL permits historical rows while
            // the value 1 reserves the single active certification.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->foreignId('certified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at');
            $table->string('public_note', 500)->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 40)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(
                ['query_template_id', 'active_slot'],
                'query_template_certifications_active_unique',
            );
            $table->index(
                ['query_template_id', 'certified_at'],
                'query_template_certifications_history_index',
            );
            $table->index(
                ['query_template_version_id', 'certified_at'],
                'query_template_certifications_version_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_template_certifications');

        Schema::table('query_template_versions', function (Blueprint $table) {
            $table->dropIndex('query_template_versions_restoration_index');
            $table->dropConstrainedForeignId('restored_from_version_id');
        });
    }
};
