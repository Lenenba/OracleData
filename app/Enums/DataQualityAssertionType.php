<?php

namespace App\Enums;

enum DataQualityAssertionType: string
{
    case NonEmpty = 'non_empty';
    case RowCountRange = 'row_count_range';
    case Unique = 'unique';
    case RequiredFields = 'required_fields';
    case AllowedValues = 'allowed_values';
    case MaxDuration = 'max_duration';
    case ReferenceEquivalence = 'reference_equivalence';
}
