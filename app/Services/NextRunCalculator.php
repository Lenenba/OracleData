<?php

namespace App\Services;

use App\Enums\ScheduleFrequency;
use App\Models\QuerySchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Computes the next UTC run instant of a schedule from its preset frequency,
 * evaluated in the schedule's own timezone so daily/weekly times land locally.
 */
class NextRunCalculator
{
    public function next(QuerySchedule $schedule, ?CarbonInterface $from = null): Carbon
    {
        $timezone = $schedule->timezone !== '' ? $schedule->timezone : 'UTC';
        $reference = CarbonImmutable::parse($from ?? CarbonImmutable::now())->setTimezone($timezone);

        $next = match ($schedule->frequency) {
            ScheduleFrequency::Hourly => $reference->startOfHour()->addHour(),
            ScheduleFrequency::Daily => $this->nextDaily($reference, (string) $schedule->time_of_day),
            ScheduleFrequency::Weekly => $this->nextWeekly(
                $reference,
                (string) $schedule->time_of_day,
                (int) $schedule->day_of_week,
            ),
        };

        return Carbon::instance($next->utc());
    }

    private function nextDaily(CarbonImmutable $reference, string $timeOfDay): CarbonImmutable
    {
        $candidate = $this->atTime($reference, $timeOfDay);

        return $candidate->lessThanOrEqualTo($reference)
            ? $candidate->addDay()
            : $candidate;
    }

    private function nextWeekly(CarbonImmutable $reference, string $timeOfDay, int $dayOfWeek): CarbonImmutable
    {
        $candidate = $this->atTime($reference, $timeOfDay);
        $daysUntil = ($dayOfWeek - $candidate->dayOfWeek + 7) % 7;
        $candidate = $candidate->addDays($daysUntil);

        return $candidate->lessThanOrEqualTo($reference)
            ? $candidate->addWeek()
            : $candidate;
    }

    private function atTime(CarbonImmutable $reference, string $timeOfDay): CarbonImmutable
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $timeOfDay)), 2, 0);

        return $reference->setTime($hour, $minute, 0);
    }
}
