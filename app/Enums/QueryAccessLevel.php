<?php

namespace App\Enums;

enum QueryAccessLevel: string
{
    case PRIVATE = 'private';

    case RESTRICTED = 'restricted';

    case ORGANIZATION = 'organization';
}
