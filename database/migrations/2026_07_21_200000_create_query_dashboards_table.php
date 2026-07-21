<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 10B — composable dashboards.
 *
 * query_dashboards : one row per dashboard owned by a user.
 * query_dashboard_widgets : positioned, typed widgets on a dashboard.
 *
 * Widget types:
 *   - kpi    : scalar count from a query column
 *   - table  : tabular results from a query
 *   - chart  : SVG bar/line chart from a query column
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_dashboards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('description', 1000)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('query_dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_id')
                ->constrained('query_dashboards')
                ->cascadeOnDelete();
            $table->foreignId('query_id')
                ->constrained('queries')
                ->cascadeOnDelete();
            $table->string('widget_type', 20); // kpi | table | chart
            $table->string('title', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('widget_options')->nullable(); // column, chart_type, limit, etc.
            $table->timestamps();

            $table->index(['dashboard_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_dashboard_widgets');
        Schema::dropIfExists('query_dashboards');
    }
};
