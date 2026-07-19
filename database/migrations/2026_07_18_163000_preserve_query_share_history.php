<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string LEGACY_UNIQUE = 'query_user_shares_query_recipient_unique';

    private const string LIFECYCLE_INDEX = 'query_user_shares_query_recipient_status_index';

    /**
     * Replace the original one-share-per-recipient constraint with a lookup
     * index so revoked and expired grant lifecycles remain immutable.
     */
    public function up(): void
    {
        if (Schema::hasIndex('query_user_shares', self::LEGACY_UNIQUE)) {
            Schema::table('query_user_shares', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        if (! Schema::hasIndex('query_user_shares', self::LIFECYCLE_INDEX)) {
            Schema::table('query_user_shares', function (Blueprint $table): void {
                $table->index(
                    ['query_id', 'user_id', 'status'],
                    self::LIFECYCLE_INDEX,
                );
            });
        }
    }

    /**
     * Restore the legacy constraint only when no lifecycle history would be
     * lost or made ambiguous by the rollback.
     */
    public function down(): void
    {
        $hasMultipleLifecycles = DB::table('query_user_shares')
            ->select(['query_id', 'user_id'])
            ->groupBy('query_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleLifecycles) {
            throw new RuntimeException(
                'Cannot restore the legacy query-share uniqueness constraint while lifecycle history exists.',
            );
        }

        if (Schema::hasIndex('query_user_shares', self::LIFECYCLE_INDEX)) {
            Schema::table('query_user_shares', function (Blueprint $table): void {
                $table->dropIndex(self::LIFECYCLE_INDEX);
            });
        }

        if (! Schema::hasIndex('query_user_shares', self::LEGACY_UNIQUE)) {
            Schema::table('query_user_shares', function (Blueprint $table): void {
                $table->unique(
                    ['query_id', 'user_id'],
                    self::LEGACY_UNIQUE,
                );
            });
        }
    }
};
