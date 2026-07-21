<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 10C — governed aggregate captures per query.
     *
     * Each row represents one "daily snapshot" of execution statistics for a
     * saved query. One row is created or updated per calendar day (UTC) when a
     * successful run is recorded, so the table grows at most one row per query
     * per day. The snapshot is immutable once the day has closed; the current
     * day's row is updated in place until midnight UTC.
     *
     * Columns:
     *  - period_date    ISO date of the capture window (one row per query per day)
     *  - run_count      total runs (succeeded only) in this window
     *  - rows_min/max   row-count boundaries across runs in the window
     *  - duration_min/max/avg_ms  Oracle round-trip boundaries and mean
     *  - last_run_at    timestamp of the last included run
     */
    public function up(): void
    {
        Schema::create('query_execution_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('query_id')
                ->constrained(indexName: 'qea_query_fk')
                ->cascadeOnDelete();
            $table->date('period_date');
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('rows_min')->default(0);
            $table->unsignedInteger('rows_max')->default(0);
            $table->unsignedInteger('duration_min_ms')->default(0);
            $table->unsignedInteger('duration_max_ms')->default(0);
            $table->unsignedInteger('duration_avg_ms')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['query_id', 'period_date'], 'qea_query_date_unique');
            $table->index(['query_id', 'period_date'], 'qea_query_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_execution_aggregates');
    }
};
