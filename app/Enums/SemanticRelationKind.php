<?php

namespace App\Enums;

enum SemanticRelationKind: string
{
    case Expand = 'expand';
    case Join = 'join';
    case Reference = 'reference';
}
