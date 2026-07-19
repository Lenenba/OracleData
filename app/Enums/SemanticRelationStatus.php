<?php

namespace App\Enums;

enum SemanticRelationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Deprecated = 'deprecated';
}
