<?php

namespace App\Enums;

enum OracleSchemaImpactStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
