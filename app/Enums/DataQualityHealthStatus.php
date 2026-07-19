<?php

namespace App\Enums;

enum DataQualityHealthStatus: string
{
    case Unknown = 'unknown';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failing = 'failing';
}
