<?php

namespace App\Domain\Query;

use App\Domain\Resource\ResourceDefinition;
use InvalidArgumentException;

/**
 * Une occurrence de ressource dans une branche d'exécution.
 *
 * Deux frames peuvent référencer la même ressource sans se confondre, car
 * l'identité d'exécution est portée par nodeId et par la chaîne parent.
 */
final class ExecutionFrame
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function __construct(
        public readonly string $nodeId,
        ResourceDefinition $resource,
        public readonly array $row,
        public readonly ?self $parent = null,
    ) {
        if (trim($this->nodeId) === '') {
            throw new InvalidArgumentException('Execution frame node id must not be empty.');
        }

        $this->resourceId = $resource->id;
        $this->identifiers = $resource->identifiersFromRow($row);
    }

    public readonly string $resourceId;

    /** @var array<string, string|int> */
    public readonly array $identifiers;
}
