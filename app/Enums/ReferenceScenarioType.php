<?php

namespace App\Enums;

enum ReferenceScenarioType: string
{
    case Baseline = 'baseline';
    case Filter = 'filter';
    case Join = 'join';
    case Duplicates = 'duplicates';
    case Order = 'order';
    case Aggregate = 'aggregate';
    case Nulls = 'nulls';
}
