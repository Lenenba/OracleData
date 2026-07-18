<?php

namespace App\Enums;

/**
 * Controls whether an Oracle read may use compatibility fallbacks.
 */
enum OracleExecutionPolicy: string
{
    case BEST_EFFORT = 'best_effort';
    case EXACT = 'exact';

    public function allowsFallbacks(): bool
    {
        return $this === self::BEST_EFFORT;
    }
}
