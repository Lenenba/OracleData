<?php

use App\Enums\ScheduleFrequency;
use App\Models\QuerySchedule;
use App\Services\NextRunCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->calculator = new NextRunCalculator;
});

/**
 * Build an unsaved schedule with placeholder foreign keys so the calculation
 * stays purely in memory.
 *
 * @param  array<string, mixed>  $attributes
 */
function scheduleFor(array $attributes): QuerySchedule
{
    return QuerySchedule::factory()->make(array_merge([
        'user_id' => 1,
        'query_id' => 1,
    ], $attributes));
}

test('hourly schedules run at the top of the next hour', function () {
    $schedule = scheduleFor([
        'frequency' => ScheduleFrequency::Hourly,
        'time_of_day' => null,
        'timezone' => 'UTC',
    ]);

    $next = $this->calculator->next($schedule, CarbonImmutable::parse('2026-07-20 08:15:00', 'UTC'));

    expect($next->format('Y-m-d H:i:s'))->toBe('2026-07-20 09:00:00');
});

test('daily schedules run today when the time is still ahead', function () {
    $schedule = scheduleFor([
        'frequency' => ScheduleFrequency::Daily,
        'time_of_day' => '08:00',
        'timezone' => 'UTC',
    ]);

    $next = $this->calculator->next($schedule, CarbonImmutable::parse('2026-07-20 07:00:00', 'UTC'));

    expect($next->format('Y-m-d H:i:s'))->toBe('2026-07-20 08:00:00');
});

test('daily schedules roll to tomorrow once the time has passed', function () {
    $schedule = scheduleFor([
        'frequency' => ScheduleFrequency::Daily,
        'time_of_day' => '08:00',
        'timezone' => 'UTC',
    ]);

    $next = $this->calculator->next($schedule, CarbonImmutable::parse('2026-07-20 09:00:00', 'UTC'));

    expect($next->format('Y-m-d H:i:s'))->toBe('2026-07-21 08:00:00');
});

test('weekly schedules target the configured weekday', function () {
    // 2026-07-20 is a Monday (dayOfWeek 1); ask for the next Wednesday (3).
    $schedule = scheduleFor([
        'frequency' => ScheduleFrequency::Weekly,
        'time_of_day' => '08:00',
        'day_of_week' => 3,
        'timezone' => 'UTC',
    ]);

    $next = $this->calculator->next($schedule, CarbonImmutable::parse('2026-07-20 09:00:00', 'UTC'));

    expect($next->format('Y-m-d H:i:s'))->toBe('2026-07-22 08:00:00')
        ->and($next->dayOfWeek)->toBe(3);
});

test('the schedule timezone is honoured when converting to UTC', function () {
    // 08:00 in Toronto (UTC-4 in July) is 12:00 UTC.
    $schedule = scheduleFor([
        'frequency' => ScheduleFrequency::Daily,
        'time_of_day' => '08:00',
        'timezone' => 'America/Toronto',
    ]);

    $next = $this->calculator->next($schedule, CarbonImmutable::parse('2026-07-20 00:00:00', 'UTC'));

    expect($next->format('Y-m-d H:i:s'))->toBe('2026-07-20 12:00:00');
});
