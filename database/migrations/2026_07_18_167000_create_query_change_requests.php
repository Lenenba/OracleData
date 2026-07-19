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
        Schema::create('query_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('title', 160);
            $table->string('status', 20)->default('pending');
            $table->foreignId('status_changed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['query_id', 'status', 'created_at'],
                'query_change_requests_query_status_index',
            );
            $table->index(
                ['requested_by_user_id', 'status', 'created_at'],
                'query_change_requests_requester_status_index',
            );
        });

        Schema::create('query_change_request_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_change_request_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(
                ['query_change_request_id', 'created_at'],
                'query_change_request_comments_thread_index',
            );
        });

        Schema::create('query_change_request_comment_mentions', function (Blueprint $table) {
            $table->foreignId('query_change_request_comment_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->foreign(
                'query_change_request_comment_id',
                'query_change_request_mentions_comment_fk',
            )
                ->references('id')
                ->on('query_change_request_comments')
                ->cascadeOnDelete();

            $table->primary(
                ['query_change_request_comment_id', 'user_id'],
                'query_change_request_comment_mentions_primary',
            );
            $table->index(
                ['user_id', 'created_at'],
                'query_change_request_mentions_user_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('query_change_request_comment_mentions');
        Schema::dropIfExists('query_change_request_comments');
        Schema::dropIfExists('query_change_requests');
    }
};
