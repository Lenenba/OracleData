<?php

namespace App\Domain\Query\Filter;

enum FilterOperator: string
{
    case Equal = 'eq';
    case NotEqual = 'ne';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case In = 'in';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';

    public function requiresNoValue(): bool
    {
        return $this === self::IsNull || $this === self::IsNotNull;
    }
}
