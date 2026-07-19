<?php

namespace App\Enums;

enum DataQualityRunPurpose: string
{
    case Manual = 'manual';
    case PrePublication = 'pre_publication';
    case Monitoring = 'monitoring';
}
