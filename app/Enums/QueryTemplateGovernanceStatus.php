<?php

namespace App\Enums;

enum QueryTemplateGovernanceStatus: string
{
    case DRAFT = 'draft';
    case REVIEW = 'review';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';
}
