<?php

use App\Enums\QueryTemplateRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->timestamps();
        });

        Schema::create('query_template_role_user', function (Blueprint $table) {
            $table->foreignId('query_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['query_template_id', 'role_id', 'user_id'],
                'query_template_role_user_unique',
            );
            $table->index(
                ['user_id', 'query_template_id'],
                'query_template_role_user_lookup_index',
            );
        });

        Schema::table('query_templates', function (Blueprint $table) {
            $table->foreignId('technical_owner_user_id')
                ->nullable()
                ->after('business_owner_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(
                ['technical_owner_user_id', 'governance_status'],
                'query_templates_technical_owner_index',
            );
        });

        DB::table('roles')->insert(array_map(
            fn (QueryTemplateRole $role): array => [
                'name' => $role->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            QueryTemplateRole::cases(),
        ));
    }

    public function down(): void
    {
        Schema::table('query_templates', function (Blueprint $table) {
            $table->dropIndex('query_templates_technical_owner_index');
            $table->dropConstrainedForeignId('technical_owner_user_id');
        });

        Schema::dropIfExists('query_template_role_user');
        Schema::dropIfExists('roles');
    }
};
