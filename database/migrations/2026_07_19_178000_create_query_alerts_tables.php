<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_schedule_id')
                ->constrained(indexName: 'query_alerts_schedule_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(indexName: 'query_alerts_user_fk')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('condition', 20);
            $table->integer('threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['query_schedule_id', 'is_active'], 'query_alerts_schedule_active_index');
            $table->index(['user_id', 'id'], 'query_alerts_owner_index');
        });

        Schema::create('query_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_alert_id')
                ->constrained(indexName: 'query_alert_events_alert_fk')
                ->cascadeOnDelete();
            $table->foreignId('query_schedule_run_id')
                ->constrained(indexName: 'query_alert_events_run_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(indexName: 'query_alert_events_user_fk')
                ->cascadeOnDelete();
            $table->integer('observed_value')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table->index(['query_alert_id', 'triggered_at'], 'query_alert_events_alert_triggered_index');
            $table->index(['user_id', 'triggered_at'], 'query_alert_events_owner_triggered_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_alert_events');
        Schema::dropIfExists('query_alerts');
    }
};
