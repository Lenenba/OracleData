<?php

namespace App\Enums;

enum SemanticSqlMappingStatus: string
{
    case Unmapped = 'unmapped';
    case Exact = 'exact';
    case Derived = 'derived';
    case Unsupported = 'unsupported';
}
