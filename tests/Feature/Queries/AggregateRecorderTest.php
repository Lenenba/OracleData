<?php

use App\Models\Query;
use App\Models\QueryExecutionAggregate;
use App\Models\User;
use App\Services\AggregateRecorder;
use Illuminate\Support\Carbon;

test('daily aggregate updates the existing date row on repeated runs', function () {
    $query = Query::factory()
        ->for(User::factory())
        ->create();
    $recorder = app(AggregateRecorder::class);
    $ranAt = Carbon::parse('2026-07-30 12:00:00', 'UTC');

    $recorder->record($query->id, 10, 100, $ranAt);
    $recorder->record($query->id, 20, 300, $ranAt->addHour());

    $aggregate = QueryExecutionAggregate::query()->sole();

    expect($aggregate->run_count)->toBe(2)
        ->and($aggregate->rows_min)->toBe(10)
        ->and($aggregate->rows_max)->toBe(20)
        ->and($aggregate->duration_min_ms)->toBe(100)
        ->and($aggregate->duration_max_ms)->toBe(300)
        ->and($aggregate->duration_avg_ms)->toBe(200);
});

test('daily aggregate derives its calendar day from the run timestamp in UTC', function () {
    $query = Query::factory()
        ->for(User::factory())
        ->create();
    $recorder = app(AggregateRecorder::class);
    $ranAt = Carbon::parse('2026-07-30 20:30:00', 'America/Toronto');

    $recorder->record($query->id, 10, 100, $ranAt);

    $aggregate = QueryExecutionAggregate::query()->sole();

    expect($aggregate->period_date->toDateString())->toBe('2026-07-31');
});
