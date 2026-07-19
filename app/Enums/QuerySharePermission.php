<?php

namespace App\Enums;

enum QuerySharePermission: string
{
    case VIEW = 'view';

    case EXECUTE = 'execute';

    case CLONE = 'clone';

    case MANAGE = 'manage';

    /**
     * Explicitly orders grants from least to most permissive.
     */
    public function rank(): int
    {
        return match ($this) {
            self::VIEW => 10,
            self::EXECUTE => 20,
            self::CLONE => 30,
            self::MANAGE => 40,
        };
    }

    public function implies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /**
     * @return list<self>
     */
    public static function granting(self $required): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission): bool => $permission->implies($required),
        ));
    }

    /**
     * @return list<string>
     */
    public static function valuesGranting(self $required): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::granting($required),
        );
    }
}
