<?php

namespace App\Enums;

enum AlertCondition: string
{
    case RowCountAbove = 'row_count_above';
    case RowCountBelow = 'row_count_below';
    case RunFailed = 'run_failed';

    /** Row-count conditions compare the run against a numeric threshold. */
    public function requiresThreshold(): bool
    {
        return $this !== self::RunFailed;
    }
}
