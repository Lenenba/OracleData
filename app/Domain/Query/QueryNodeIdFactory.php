<?php

namespace App\Domain\Query;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Produit les identifiants techniques des instances de nœuds.
 *
 * Ces IDs identifient une occurrence dans le Query Graph. Ils ne doivent
 * jamais être dérivés d'un identifiant Oracle ou d'une donnée métier.
 */
final class QueryNodeIdFactory
{
    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    public static function isValid(string $nodeId): bool
    {
        return Str::isUuid($nodeId) || Str::isUlid($nodeId);
    }

    public static function assertValid(string $nodeId): void
    {
        if (! self::isValid($nodeId)) {
            throw new InvalidArgumentException(
                "Query nodeId '{$nodeId}' must be a valid UUID or ULID.",
            );
        }
    }
}
