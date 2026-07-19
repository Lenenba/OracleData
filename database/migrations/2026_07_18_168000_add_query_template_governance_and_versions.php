<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('query_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('draft');
            // MySQL and SQLite both allow several NULL values in this unique
            // key. The value 1 therefore reserves the single open draft/review.
            $table->unsignedTinyInteger('open_slot')->nullable();
            $table->json('definition');
            $table->json('translations');
            $table->char('content_hash', 64);
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(
                ['query_template_id', 'version_number'],
                'query_template_versions_number_unique',
            );
            $table->unique(
                ['query_template_id', 'open_slot'],
                'query_template_versions_open_unique',
            );
            $table->index(
                ['query_template_id', 'status'],
                'query_template_versions_status_index',
            );
        });

        Schema::table('query_templates', function (Blueprint $table) {
            $table->string('governance_status', 20)->default('draft')->after('is_active');
            $table->foreignId('published_version_id')
                ->nullable()
                ->after('governance_status')
                ->constrained('query_template_versions')
                ->nullOnDelete();
            $table->foreignId('business_owner_user_id')
                ->nullable()
                ->after('published_version_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->date('review_due_at')->nullable()->after('business_owner_user_id');
            $table->timestamp('published_at')->nullable()->after('review_due_at');
            $table->foreignId('published_by_user_id')
                ->nullable()
                ->after('published_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('published_by_user_id');
            $table->foreignId('archived_by_user_id')
                ->nullable()
                ->after('archived_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1)->after('archived_by_user_id');

            $table->index(
                ['governance_status', 'is_active', 'sort_order'],
                'query_templates_governance_library_index',
            );
            $table->index(
                ['business_owner_user_id', 'review_due_at'],
                'query_templates_owner_review_index',
            );
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->foreignId('query_template_version_id')
                ->nullable()
                ->after('query_template_id')
                ->constrained('query_template_versions')
                ->nullOnDelete();
        });

        Schema::table('query_executions', function (Blueprint $table) {
            $table->foreignId('query_template_version_id')
                ->nullable()
                ->after('query_template_id')
                ->constrained('query_template_versions')
                ->nullOnDelete();
        });

        $this->backfillPublishedVersions();
    }

    /**
     * Reverse the migrations while leaving the last published projection in
     * the legacy query_templates and translation tables.
     */
    public function down(): void
    {
        Schema::table('query_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('query_template_version_id');
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('query_template_version_id');
        });

        Schema::table('query_templates', function (Blueprint $table) {
            $table->dropIndex('query_templates_governance_library_index');
            $table->dropIndex('query_templates_owner_review_index');
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropConstrainedForeignId('business_owner_user_id');
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropColumn([
                'governance_status',
                'review_due_at',
                'published_at',
                'archived_at',
                'lock_version',
            ]);
        });

        Schema::dropIfExists('query_template_versions');
    }

    private function backfillPublishedVersions(): void
    {
        DB::table('query_templates')
            ->orderBy('id')
            ->get()
            ->each(function (object $template): void {
                $translations = DB::table('query_template_translations')
                    ->where('query_template_id', $template->id)
                    ->orderBy('locale')
                    ->get([
                        'locale',
                        'name',
                        'description',
                        'parameter_labels',
                        'parameter_descriptions',
                        'parameter_options',
                    ])
                    ->mapWithKeys(function (object $translation): array {
                        return [(string) $translation->locale => [
                            'name' => (string) $translation->name,
                            'description' => $translation->description,
                            'parameter_labels' => $this->decodeJson($translation->parameter_labels),
                            'parameter_descriptions' => $this->decodeJson($translation->parameter_descriptions),
                            'parameter_options' => $this->decodeJson($translation->parameter_options),
                        ]];
                    })
                    ->all();
                $definition = [
                    'name' => (string) $template->name,
                    'description' => $template->description,
                    'category_id' => $template->category_id,
                    'resource_key' => (string) $template->resource_key,
                    'resource_path' => (string) $template->resource_path,
                    'parameters' => $this->decodeJson($template->parameters),
                    'parameter_definitions' => $this->decodeJson($template->parameter_definitions),
                    'sort_order' => (int) $template->sort_order,
                ];
                $definitionJson = json_encode($definition, JSON_THROW_ON_ERROR);
                $translationsJson = json_encode($translations, JSON_THROW_ON_ERROR);
                $contentJson = json_encode(
                    [$definition, $translations],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                $publishedAt = $template->updated_at ?? $template->created_at ?? now();

                $versionId = DB::table('query_template_versions')->insertGetId([
                    'query_template_id' => $template->id,
                    'version_number' => 1,
                    'status' => 'published',
                    'open_slot' => null,
                    'definition' => $definitionJson,
                    'translations' => $translationsJson,
                    'content_hash' => hash('sha256', $contentJson),
                    'change_summary' => null,
                    'created_by_user_id' => null,
                    'submitted_by_user_id' => null,
                    'published_by_user_id' => null,
                    'submitted_at' => $publishedAt,
                    'published_at' => $publishedAt,
                    'lock_version' => 1,
                    'created_at' => $template->created_at ?? $publishedAt,
                    'updated_at' => $publishedAt,
                ]);
                $governanceStatus = (bool) $template->is_active ? 'published' : 'archived';

                DB::table('query_templates')->where('id', $template->id)->update([
                    'governance_status' => $governanceStatus,
                    'published_version_id' => $versionId,
                    'published_at' => $publishedAt,
                    'archived_at' => $governanceStatus === 'archived' ? $publishedAt : null,
                    'lock_version' => 1,
                ]);
                $derivedQueryIds = DB::table('queries')
                    ->where('query_template_id', $template->id)
                    ->pluck('id');
                DB::table('queries')
                    ->where('query_template_id', $template->id)
                    ->whereNull('query_template_version_id')
                    ->update(['query_template_version_id' => $versionId]);
                DB::table('query_executions')
                    ->where('query_template_id', $template->id)
                    ->whereNull('query_template_version_id')
                    ->update(['query_template_version_id' => $versionId]);

                if ($derivedQueryIds->isNotEmpty()) {
                    DB::table('query_executions')
                        ->whereIn('query_id', $derivedQueryIds)
                        ->whereNull('query_template_version_id')
                        ->update(['query_template_version_id' => $versionId]);
                }
            });
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
};
