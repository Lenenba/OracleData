<?php

namespace App\Enums;

enum SemanticClassification: string
{
    case Unclassified = 'unclassified';
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
    case Restricted = 'restricted';
}
