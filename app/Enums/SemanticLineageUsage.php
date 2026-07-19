<?php

namespace App\Enums;

enum SemanticLineageUsage: string
{
    case Resource = 'resource';
    case Select = 'select';
    case Filter = 'filter';
    case Sort = 'sort';
    case Expand = 'expand';
    case JoinSource = 'join_source';
    case JoinTarget = 'join_target';
}
