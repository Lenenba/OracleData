<?php

use App\Models\QueryDashboardWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a dashboard widget factory keeps its dashboard and source query under the same owner', function () {
    $widget = QueryDashboardWidget::factory()->create();

    expect($widget->dashboard->user_id)
        ->toBe($widget->sourceQuery->user_id);
});
