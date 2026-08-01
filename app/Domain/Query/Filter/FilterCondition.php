<?php

namespace App\Domain\Query\Filter;

use App\Domain\Resource\FieldDefinition;
use App\Domain\Resource\ResourceDefinition;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class FilterCondition
{
    public const int MAX_LIST_VALUES = 100;

    private const array ALLOWED_KEYS = ['field', 'operator', 'value'];

    public function __construct(
        public readonly FieldDefinition $field,
        public readonly FilterOperator $operator,
        public readonly mixed $value,
    ) {
        if (! $this->field->queryable) {
            throw new InvalidArgumentException(
                "Structured filter field '{$this->field->name}' is not queryable.",
            );
        }

        $this->assertOperatorAllowed();
        $this->assertValueCompatible();
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload, ResourceDefinition $resource): self
    {
        self::assertKnownKeys($payload);

        $fieldName = $payload['field'] ?? null;
        if (! is_string($fieldName) || $fieldName === '') {
            throw new InvalidArgumentException('A structured filter field must be a non-empty string.');
        }

        $field = $resource->field($fieldName);
        if ($field === null) {
            throw new InvalidArgumentException(
                "Structured filter field '{$fieldName}' is not governed by resource '{$resource->id}'.",
            );
        }

        $operatorValue = $payload['operator'] ?? null;
        $operator = is_string($operatorValue)
            ? FilterOperator::tryFrom($operatorValue)
            : null;

        if ($operator === null) {
            $rendered = is_scalar($operatorValue) ? (string) $operatorValue : get_debug_type($operatorValue);

            throw new InvalidArgumentException(
                "Structured filter operator '{$rendered}' is not supported.",
            );
        }

        if (! $operator->requiresNoValue() && ! array_key_exists('value', $payload)) {
            throw new InvalidArgumentException(
                "Structured filter operator '{$operator->value}' requires a value.",
            );
        }

        return new self(
            field: $field,
            operator: $operator,
            value: $payload['value'] ?? null,
        );
    }

    /** @return array{field: string, operator: string, value: mixed} */
    public function toArray(): array
    {
        return [
            'field' => $this->field->name,
            'operator' => $this->operator->value,
            'value' => $this->value,
        ];
    }

    private function assertOperatorAllowed(): void
    {
        $allowed = match (strtolower($this->field->type)) {
            'string' => [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::Contains,
                FilterOperator::StartsWith,
                FilterOperator::In,
                FilterOperator::IsNull,
                FilterOperator::IsNotNull,
            ],
            'integer', 'number', 'decimal', 'float' => [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::GreaterThan,
                FilterOperator::GreaterThanOrEqual,
                FilterOperator::LessThan,
                FilterOperator::LessThanOrEqual,
                FilterOperator::In,
                FilterOperator::IsNull,
                FilterOperator::IsNotNull,
            ],
            'date', 'datetime' => [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::GreaterThan,
                FilterOperator::GreaterThanOrEqual,
                FilterOperator::LessThan,
                FilterOperator::LessThanOrEqual,
                FilterOperator::In,
                FilterOperator::IsNull,
                FilterOperator::IsNotNull,
            ],
            'boolean' => [
                FilterOperator::Equal,
                FilterOperator::NotEqual,
                FilterOperator::IsNull,
                FilterOperator::IsNotNull,
            ],
            default => throw new InvalidArgumentException(
                "Field '{$this->field->name}' has unsupported filter type '{$this->field->type}'.",
            ),
        };

        if (! in_array($this->operator, $allowed, true)) {
            throw new InvalidArgumentException(
                "Operator '{$this->operator->value}' is incompatible with field '{$this->field->name}' "
                ."of type '{$this->field->type}'.",
            );
        }
    }

    private function assertValueCompatible(): void
    {
        if ($this->operator->requiresNoValue()) {
            if ($this->value !== null) {
                throw new InvalidArgumentException(
                    "Operator '{$this->operator->value}' must not receive a value.",
                );
            }

            return;
        }

        if ($this->value === null) {
            throw new InvalidArgumentException(
                "Operator '{$this->operator->value}' requires a non-null value.",
            );
        }

        if ($this->operator === FilterOperator::In) {
            if (
                ! is_array($this->value)
                || ! array_is_list($this->value)
                || $this->value === []
                || count($this->value) > self::MAX_LIST_VALUES
            ) {
                throw new InvalidArgumentException(
                    "Operator 'in' requires between 1 and ".self::MAX_LIST_VALUES.' values.',
                );
            }

            foreach ($this->value as $value) {
                $this->assertScalarValueCompatible($value);
            }

            return;
        }

        $this->assertScalarValueCompatible($this->value);
    }

    private function assertScalarValueCompatible(mixed $value): void
    {
        $compatible = match (strtolower($this->field->type)) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number', 'decimal', 'float' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
            'date' => self::isDate($value),
            'datetime' => self::isDateTime($value),
            default => false,
        };

        if (! $compatible) {
            throw new InvalidArgumentException(
                "Value for field '{$this->field->name}' is incompatible with type '{$this->field->type}'.",
            );
        }
    }

    private static function isDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function isDateTime(mixed $value): bool
    {
        if (
            ! is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                $value,
            ) !== 1
        ) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false
                || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertKnownKeys(array $payload): void
    {
        $unknown = array_values(array_diff(array_keys($payload), self::ALLOWED_KEYS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Structured filter condition contains unsupported key '{$unknown[0]}'.",
            );
        }
    }
}
