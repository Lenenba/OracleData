<?php

namespace App\Enums;

enum DataQualityRunStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
}
