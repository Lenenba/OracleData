<?php

namespace App\Enums;

enum QueryTemplateVersionStatus: string
{
    case DRAFT = 'draft';
    case REVIEW = 'review';
    case PUBLISHED = 'published';
    case SUPERSEDED = 'superseded';

    public function isContentMutable(): bool
    {
        return $this === self::DRAFT;
    }
}
