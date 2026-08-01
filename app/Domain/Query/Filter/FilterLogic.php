<?php

namespace App\Domain\Query\Filter;

enum FilterLogic: string
{
    case And = 'and';
    case Or = 'or';
}
