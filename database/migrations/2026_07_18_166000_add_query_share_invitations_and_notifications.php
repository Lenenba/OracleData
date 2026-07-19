<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string RECIPIENT_PENDING_INDEX = 'query_user_shares_recipient_pending_index';

    private const string QUERY_PENDING_INDEX = 'query_user_shares_query_pending_index';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('query_user_shares', function (Blueprint $table) {
            // A string keeps the lifecycle extensible while the model remains
            // the single source of truth for accepted status values.
            $table->string('status', 20)->default('accepted')->change();
            $table->timestamp('respond_by')->nullable()->after('expires_at');
            $table->timestamp('declined_at')->nullable()->after('accepted_at');
            $table->timestamp('cancelled_at')->nullable()->after('declined_at');
            $table->index(
                ['user_id', 'status', 'respond_by'],
                self::RECIPIENT_PENDING_INDEX,
            );
            $table->index(
                ['query_id', 'status', 'respond_by'],
                self::QUERY_PENDING_INDEX,
            );
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations without silently discarding invitation history.
     */
    public function down(): void
    {
        $hasInvitationHistory = DB::table('query_user_shares')
            ->whereNotIn('status', ['accepted', 'revoked'])
            ->exists();

        if ($hasInvitationHistory) {
            throw new RuntimeException(
                'Cannot remove query-share invitation support while invitation history exists.',
            );
        }

        Schema::dropIfExists('notifications');

        Schema::table('query_user_shares', function (Blueprint $table) {
            $table->dropIndex(self::RECIPIENT_PENDING_INDEX);
            $table->dropIndex(self::QUERY_PENDING_INDEX);
            $table->dropColumn(['respond_by', 'declined_at', 'cancelled_at']);
            $table->enum('status', ['accepted', 'revoked'])
                ->default('accepted')
                ->change();
        });
    }
};
