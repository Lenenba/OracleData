<?php

namespace App\Domain\Query\Filter;

use App\Domain\Resource\ResourceDefinition;
use InvalidArgumentException;

final class FilterGroup
{
    public const int MAX_DEPTH = 3;

    public const int MAX_CONDITIONS = 20;

    private const array ALLOWED_KEYS = ['logic', 'conditions'];

    /**
     * @param  list<FilterCondition|FilterGroup>  $conditions
     */
    private function __construct(
        public readonly FilterLogic $logic,
        public readonly array $conditions,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload, ResourceDefinition $resource): self
    {
        $conditionCount = 0;

        return self::build($payload, $resource, 1, $conditionCount);
    }

    /** @return array{logic: string, conditions: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'logic' => $this->logic->value,
            'conditions' => array_map(
                fn (FilterCondition|self $condition): array => $condition->toArray(),
                $this->conditions,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function build(
        array $payload,
        ResourceDefinition $resource,
        int $depth,
        int &$conditionCount,
    ): self {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'Structured filter group depth exceeds '.self::MAX_DEPTH.'.',
            );
        }

        self::assertKnownKeys($payload);

        $logicValue = $payload['logic'] ?? null;
        $logic = is_string($logicValue) ? FilterLogic::tryFrom($logicValue) : null;

        if ($logic === null) {
            throw new InvalidArgumentException("Structured filter logic must be 'and' or 'or'.");
        }

        $items = $payload['conditions'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new InvalidArgumentException(
                'Structured filter group conditions must be a non-empty list.',
            );
        }

        $conditions = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException(
                    'Each structured filter entry must be an object-shaped array.',
                );
            }

            if (array_key_exists('logic', $item) || array_key_exists('conditions', $item)) {
                $conditions[] = self::build($item, $resource, $depth + 1, $conditionCount);

                continue;
            }

            $conditionCount++;
            if ($conditionCount > self::MAX_CONDITIONS) {
                throw new InvalidArgumentException(
                    'Structured filter exceeds '.self::MAX_CONDITIONS.' conditions.',
                );
            }

            $conditions[] = FilterCondition::fromArray($item, $resource);
        }

        return new self($logic, $conditions);
    }

    /** @param array<string, mixed> $payload */
    private static function assertKnownKeys(array $payload): void
    {
        $unknown = array_values(array_diff(array_keys($payload), self::ALLOWED_KEYS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Structured filter group contains unsupported key '{$unknown[0]}'.",
            );
        }
    }
}
