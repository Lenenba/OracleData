<?php

namespace App\Enums;

enum OracleSchemaImpactSeverity: string
{
    case Informational = 'informational';
    case Review = 'review';
    case Breaking = 'breaking';
}
