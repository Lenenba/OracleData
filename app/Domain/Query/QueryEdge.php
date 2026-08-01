<?php

namespace App\Domain\Query;

use App\Domain\Resource\RelationDefinition;

/**
 * Lien dirigé entre deux QueryNodes dans le QueryGraph.
 *
 * QueryEdge associe un nœud parent à un nœud enfant et transporte
 * la RelationDefinition (type, pathTemplate, bindings) qui décrit
 * comment naviguer de l'un à l'autre.
 */
final class QueryEdge
{
    public function __construct(
        public readonly QueryNode $parent,
        public readonly QueryNode $child,
        public readonly RelationDefinition $relation,
    ) {}
}
