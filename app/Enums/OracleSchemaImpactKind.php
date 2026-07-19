<?php

namespace App\Enums;

enum OracleSchemaImpactKind: string
{
    case FieldAdded = 'field_added';
    case FieldRemoved = 'field_removed';
    case FieldChanged = 'field_changed';
    case RelationChanged = 'relation_changed';
}
