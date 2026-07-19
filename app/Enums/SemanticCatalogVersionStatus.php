<?php

namespace App\Enums;

enum SemanticCatalogVersionStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Superseded = 'superseded';

    public function isContentMutable(): bool
    {
        return $this === self::Draft;
    }
}
