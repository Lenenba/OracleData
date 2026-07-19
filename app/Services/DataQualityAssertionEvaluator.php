<?php

namespace App\Services;

use App\Enums\DataQualityAssertionType;
use App\Enums\DataQualityRunStatus;
use InvalidArgumentException;
use Throwable;

/**
 * Evaluates versioned data-quality rules against one in-memory Oracle result.
 *
 * Assertion reports never echo rule names, field names, allowed values or row
 * values. Only stable rule indexes/types, outcome codes and non-sensitive
 * counts are returned for persistence.
 *
 * @phpstan-type AssertionResult array{index: int, type: string, required: bool, passed: bool, code: string, metrics: array<string, bool|float|int>}
 */
class DataQualityAssertionEvaluator
{
    public function __construct(
        private readonly DatasetEquivalenceComparator $comparator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<array-key, mixed>>  $rows
     * @param  array<int|string, array<string, mixed>>  $referenceReportsById
     * @return array{
     *     status: string,
     *     passed: bool,
     *     score: float,
     *     required_failed: bool,
     *     rules_hash: string,
     *     assertions: list<array{
     *         index: int,
     *         type: string,
     *         required: bool,
     *         passed: bool,
     *         code: string,
     *         metrics: array<string, bool|float|int>
     *     }>
     * }
     */
    public function evaluate(
        array $rules,
        array $rows,
        int $durationMs,
        array $referenceReportsById = [],
    ): array {
        $this->assertInputs($rules, $rows, $durationMs);
        $results = [];
        $hasError = false;
        $requiredFailed = false;
        $passedCount = 0;

        foreach ($rules as $index => $rule) {
            if (($rule['enabled'] ?? true) === false) {
                continue;
            }

            [$result, $isError] = $this->evaluateRule(
                $index,
                $rule,
                $rows,
                $durationMs,
                $referenceReportsById,
            );
            $results[] = $result;
            $hasError = $hasError || $isError;

            if ($result['passed']) {
                $passedCount++;
            } elseif ($result['required']) {
                $requiredFailed = true;
            }
        }

        $total = count($results);
        // Optional assertions are warnings: they lower the score without
        // blocking publication or turning monitoring evidence into a failure.
        $passed = ! $hasError && ! $requiredFailed;
        $status = match (true) {
            $hasError => DataQualityRunStatus::Error,
            $passed => DataQualityRunStatus::Passed,
            default => DataQualityRunStatus::Failed,
        };

        return [
            'status' => $status->value,
            'passed' => $passed,
            'score' => $total === 0 ? 100.0 : round(($passedCount / $total) * 100, 2),
            'required_failed' => $requiredFailed,
            'rules_hash' => $this->rulesHash($rules),
            'assertions' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<array<array-key, mixed>>  $rows
     * @param  array<int|string, array<string, mixed>>  $referenceReportsById
     * @return array{AssertionResult, bool}
     */
    private function evaluateRule(
        int $index,
        array $rule,
        array $rows,
        int $durationMs,
        array $referenceReportsById,
    ): array {
        $typeValue = is_string($rule['type'] ?? null) ? $rule['type'] : 'invalid';
        $required = is_bool($rule['required'] ?? true) ? ($rule['required'] ?? true) : true;

        try {
            $type = DataQualityAssertionType::tryFrom($typeValue);

            if ($type === null
                || ! is_bool($rule['required'] ?? true)
                || ! is_array($rule['config'] ?? [])) {
                return [$this->result($index, $typeValue, $required, false, 'invalid_rule'), true];
            }

            $config = $rule['config'] ?? [];

            return match ($type) {
                DataQualityAssertionType::NonEmpty => [
                    $this->nonEmpty($index, $required, $rows),
                    false,
                ],
                DataQualityAssertionType::RowCountRange => [
                    $this->rowCountRange($index, $required, $rows, $config),
                    false,
                ],
                DataQualityAssertionType::Unique => [
                    $this->unique($index, $required, $rows, $config),
                    false,
                ],
                DataQualityAssertionType::RequiredFields => [
                    $this->requiredFields($index, $required, $rows, $config),
                    false,
                ],
                DataQualityAssertionType::AllowedValues => [
                    $this->allowedValues($index, $required, $rows, $config),
                    false,
                ],
                DataQualityAssertionType::MaxDuration => [
                    $this->maxDuration($index, $required, $durationMs, $config),
                    false,
                ],
                DataQualityAssertionType::ReferenceEquivalence => $this->reference(
                    $index,
                    $required,
                    $config,
                    $referenceReportsById,
                ),
            };
        } catch (InvalidArgumentException) {
            return [$this->result($index, $typeValue, $required, false, 'invalid_rule'), true];
        } catch (Throwable) {
            // Deliberately discard exception messages: they may originate from
            // a field/value adapter and must never enter persisted outcomes.
            return [$this->result($index, $typeValue, $required, false, 'evaluation_error'), true];
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @return AssertionResult
     */
    private function nonEmpty(int $index, bool $required, array $rows): array
    {
        $passed = $rows !== [];

        return $this->result(
            $index,
            DataQualityAssertionType::NonEmpty->value,
            $required,
            $passed,
            $passed ? 'passed' : 'empty_result',
            ['row_count' => count($rows)],
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return AssertionResult
     */
    private function rowCountRange(int $index, bool $required, array $rows, array $config): array
    {
        $minimum = $config['min'] ?? 0;
        $maximum = $config['max'] ?? null;

        if (! is_int($minimum)
            || $minimum < 0
            || ($maximum !== null && (! is_int($maximum) || $maximum < $minimum))) {
            throw new InvalidArgumentException('Invalid row-count range.');
        }

        $count = count($rows);
        $passed = $count >= $minimum && ($maximum === null || $count <= $maximum);
        $metrics = ['row_count' => $count, 'minimum' => $minimum];

        if ($maximum !== null) {
            $metrics['maximum'] = $maximum;
        }

        return $this->result(
            $index,
            DataQualityAssertionType::RowCountRange->value,
            $required,
            $passed,
            $passed ? 'passed' : 'row_count_out_of_range',
            $metrics,
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return AssertionResult
     */
    private function unique(int $index, bool $required, array $rows, array $config): array
    {
        $fields = $this->fields($config);
        $seen = [];
        $checked = 0;
        $duplicates = 0;

        foreach ($rows as $row) {
            foreach ($this->keyTuples($row, $fields) as $tuple) {
                $checked++;
                $hash = $this->comparator->fingerprintValue($tuple, 'assertion-unique-key');

                if (isset($seen[$hash])) {
                    $duplicates++;
                } else {
                    $seen[$hash] = true;
                }
            }
        }

        $passed = $duplicates === 0;

        return $this->result(
            $index,
            DataQualityAssertionType::Unique->value,
            $required,
            $passed,
            $passed ? 'passed' : 'duplicate_key',
            [
                'rows_checked' => count($rows),
                'keys_checked' => $checked,
                'distinct_keys' => count($seen),
                'duplicate_count' => $duplicates,
            ],
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return AssertionResult
     */
    private function requiredFields(int $index, bool $required, array $rows, array $config): array
    {
        $fields = $this->fields($config);
        $checked = 0;
        $violations = 0;

        foreach ($rows as $row) {
            foreach ($fields as $field) {
                [$found, $values] = $this->valuesAtPath($row, $field);

                if (! $found) {
                    $checked++;
                    $violations++;

                    continue;
                }

                foreach ($values as $value) {
                    $checked++;

                    if ($value === null) {
                        $violations++;
                    }
                }
            }
        }

        $passed = $violations === 0;

        return $this->result(
            $index,
            DataQualityAssertionType::RequiredFields->value,
            $required,
            $passed,
            $passed ? 'passed' : 'required_value_missing',
            [
                'rows_checked' => count($rows),
                'values_checked' => $checked,
                'violation_count' => $violations,
            ],
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $rows
     * @param  array<string, mixed>  $config
     * @return AssertionResult
     */
    private function allowedValues(int $index, bool $required, array $rows, array $config): array
    {
        $field = $config['field'] ?? null;
        $allowedValues = $config['values'] ?? null;

        if (! is_string($field)
            || ! $this->isSafePath($field)
            || ! is_array($allowedValues)
            || ! array_is_list($allowedValues)
            || $allowedValues === []) {
            throw new InvalidArgumentException('Invalid allowed-values rule.');
        }

        $allowed = [];

        foreach ($allowedValues as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Allowed values must be scalar.');
            }

            $allowed[$this->comparator->fingerprintValue($value, 'assertion-allowed-value')] = true;
        }

        $checked = 0;
        $violations = 0;

        foreach ($rows as $row) {
            [$found, $values] = $this->valuesAtPath($row, $field);

            if (! $found) {
                $checked++;
                $violations++;

                continue;
            }

            foreach ($values as $value) {
                $checked++;

                if (! is_scalar($value) && $value !== null) {
                    $violations++;

                    continue;
                }

                $hash = $this->comparator->fingerprintValue($value, 'assertion-allowed-value');

                if (! isset($allowed[$hash])) {
                    $violations++;
                }
            }
        }

        $passed = $violations === 0;

        return $this->result(
            $index,
            DataQualityAssertionType::AllowedValues->value,
            $required,
            $passed,
            $passed ? 'passed' : 'value_not_allowed',
            [
                'rows_checked' => count($rows),
                'values_checked' => $checked,
                'allowed_count' => count($allowed),
                'violation_count' => $violations,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return AssertionResult
     */
    private function maxDuration(int $index, bool $required, int $durationMs, array $config): array
    {
        $maximum = $config['max_ms'] ?? null;

        if (! is_int($maximum) || $maximum < 1) {
            throw new InvalidArgumentException('Invalid duration threshold.');
        }

        $passed = $durationMs <= $maximum;

        return $this->result(
            $index,
            DataQualityAssertionType::MaxDuration->value,
            $required,
            $passed,
            $passed ? 'passed' : 'duration_exceeded',
            ['duration_ms' => $durationMs, 'maximum_ms' => $maximum],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int|string, array<string, mixed>>  $referenceReportsById
     * @return array{AssertionResult, bool}
     */
    private function reference(
        int $index,
        bool $required,
        array $config,
        array $referenceReportsById,
    ): array {
        $referenceId = $config['reference_dataset_id'] ?? null;

        if (! is_int($referenceId) || $referenceId < 1) {
            return [[
                ...$this->result(
                    $index,
                    DataQualityAssertionType::ReferenceEquivalence->value,
                    $required,
                    false,
                    'invalid_rule',
                ),
            ], true];
        }

        $report = $referenceReportsById[$referenceId] ?? null;

        if (! is_array($report)) {
            return [[
                ...$this->result(
                    $index,
                    DataQualityAssertionType::ReferenceEquivalence->value,
                    $required,
                    false,
                    'reference_unavailable',
                ),
            ], true];
        }

        $booleanKeys = [
            'equivalent', 'schema_match', 'values_match', 'order_match',
            'duplicate_match', 'null_match', 'aggregate_match', 'nested_match',
        ];
        $metrics = [];

        foreach ($booleanKeys as $key) {
            if (! is_bool($report[$key] ?? null)) {
                return [[
                    ...$this->result(
                        $index,
                        DataQualityAssertionType::ReferenceEquivalence->value,
                        $required,
                        false,
                        'reference_report_invalid',
                    ),
                ], true];
            }

            $metrics[$key] = $report[$key];
        }

        foreach (($report['mismatches'] ?? []) as $key => $value) {
            if (is_string($key) && is_int($value) && $value >= 0) {
                $metrics['mismatch_'.$key] = $value;
            }
        }

        foreach (($report['counts'] ?? []) as $key => $value) {
            if (is_string($key) && is_int($value) && $value >= 0) {
                $metrics['count_'.$key] = $value;
            }
        }

        $passed = $report['equivalent'];

        return [$this->result(
            $index,
            DataQualityAssertionType::ReferenceEquivalence->value,
            $required,
            $passed,
            $passed ? 'passed' : 'reference_mismatch',
            $metrics,
        ), false];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function fields(array $config): array
    {
        $fields = $config['fields'] ?? null;

        if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
            throw new InvalidArgumentException('Assertion fields must be a list.');
        }

        $normalized = [];

        foreach ($fields as $field) {
            if (! is_string($field) || ! $this->isSafePath($field)) {
                throw new InvalidArgumentException('An assertion field path is invalid.');
            }

            $normalized[] = trim($field);
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException('Assertion fields must be unique.');
        }

        return $normalized;
    }

    /**
     * Resolve direct fields and projected nested joins. Oracle child results
     * may be a direct list or an `{items: [...]}` envelope.
     *
     * @param  array<array-key, mixed>  $row
     * @return array{bool, list<mixed>}
     */
    private function valuesAtPath(array $row, string $path): array
    {
        return $this->resolveSegments($row, explode('.', $path));
    }

    /**
     * @param  list<string>  $segments
     * @return array{bool, list<mixed>}
     */
    private function resolveSegments(mixed $value, array $segments): array
    {
        if ($segments === []) {
            return [true, [$value]];
        }

        if (! is_array($value)) {
            return [false, []];
        }

        if (array_is_list($value)) {
            $found = false;
            $values = [];

            foreach ($value as $item) {
                [$itemFound, $itemValues] = $this->resolveSegments($item, $segments);
                $found = $found || $itemFound;
                array_push($values, ...$itemValues);
            }

            return [$found, $values];
        }

        $segment = $segments[0];

        if (array_key_exists($segment, $value)) {
            return $this->resolveSegments($value[$segment], array_slice($segments, 1));
        }

        if (isset($value['items']) && is_array($value['items'])) {
            return $this->resolveSegments($value['items'], $segments);
        }

        return [false, []];
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  list<string>  $fields
     * @return list<list<mixed>>
     */
    private function keyTuples(array $row, array $fields): array
    {
        $resolved = [];
        $maximum = 1;

        foreach ($fields as $field) {
            [$found, $values] = $this->valuesAtPath($row, $field);

            if (! $found || $values === []) {
                $values = [['__oracle_data_missing__' => true]];
            }

            $resolved[] = $values;
            $maximum = max($maximum, count($values));
        }

        foreach ($resolved as $values) {
            if (count($values) !== 1 && count($values) !== $maximum) {
                throw new InvalidArgumentException('Composite nested keys have incompatible cardinalities.');
            }
        }

        $tuples = [];

        for ($index = 0; $index < $maximum; $index++) {
            $tuple = [];

            foreach ($resolved as $values) {
                $tuple[] = count($values) === 1 ? $values[0] : $values[$index];
            }

            $tuples[] = $tuple;
        }

        return $tuples;
    }

    private function isSafePath(string $path): bool
    {
        $path = trim($path);

        return $path !== ''
            && mb_strlen($path) <= 500
            && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*$/', $path) === 1;
    }

    /**
     * @param  array<string, bool|float|int>  $metrics
     * @return AssertionResult
     */
    private function result(
        int $index,
        string $type,
        bool $required,
        bool $passed,
        string $code,
        array $metrics = [],
    ): array {
        return [
            'index' => $index,
            'type' => $type,
            'required' => $required,
            'passed' => $passed,
            'code' => $code,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @param  array<array-key, mixed>  $rows
     */
    private function assertInputs(array $rules, array $rows, int $durationMs): void
    {
        if (! array_is_list($rules)) {
            throw new InvalidArgumentException('Data-quality rules must be a list.');
        }

        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Data-quality rows must be a list.');
        }

        foreach ($rules as $rule) {
            if (! is_array($rule) || array_is_list($rule)) {
                throw new InvalidArgumentException('Every data-quality rule must be an object.');
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Every data-quality row must be an object.');
            }
        }

        if ($durationMs < 0) {
            throw new InvalidArgumentException('Data-quality duration cannot be negative.');
        }
    }

    /**
     * Keep exact parity with QueryTemplateVersion::qualityRulesHash(). Rules
     * are governed technical metadata rather than Oracle result values, so a
     * deterministic unkeyed content hash is appropriate for publication CAS.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    private function rulesHash(array $rules): string
    {
        return hash('sha256', json_encode(
            $this->normalizeForHash($rules),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeForHash(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map($this->normalizeForHash(...), $value);
    }
}
