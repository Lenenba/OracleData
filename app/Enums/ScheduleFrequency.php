<?php

namespace App\Enums;

enum ScheduleFrequency: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';

    /** A time of day (HH:MM) is required for daily and weekly frequencies. */
    public function requiresTimeOfDay(): bool
    {
        return $this !== self::Hourly;
    }

    /** A day of week (0-6) is required for the weekly frequency. */
    public function requiresDayOfWeek(): bool
    {
        return $this === self::Weekly;
    }
}
