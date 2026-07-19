<?php

namespace App\Enums;

enum SemanticCardinality: string
{
    case OneToOne = 'one_to_one';
    case OneToMany = 'one_to_many';
    case ManyToOne = 'many_to_one';
    case ManyToMany = 'many_to_many';
}
