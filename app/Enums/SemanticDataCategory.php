<?php

namespace App\Enums;

enum SemanticDataCategory: string
{
    case General = 'general';
    case Personal = 'personal';
    case Financial = 'financial';
    case HumanResources = 'hr';
    case Credential = 'credential';
    case Operational = 'operational';
}
