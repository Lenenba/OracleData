<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * Builds safe dataset profiles and compares them without persisting raw rows.
 *
 * Every value-bearing element is reduced to a domain-separated keyed HMAC.
 * Reports and profiles therefore contain only HMACs, booleans and counts.
 * Canonicalization distinguishes missing keys, explicit nulls, scalar types,
 * duplicate multiplicity, row order and recursively nested join results.
 */
class DatasetEquivalenceComparator
{
    private const int PROFILE_VERSION = 1;

    private const string KEY_DERIVATION_DOMAIN = 'oracle-data/data-quality-hmac-key';

    public function __construct(
        private readonly ?string $configuredHmacKey = null,
        private readonly ?string $configuredHmacVersion = null,
    ) {}

    /**
     * @param  list<array<array-key, mixed>>  $expected
     * @param  list<array<array-key, mixed>>  $actual
     * @param  array{
     *     order_sensitive?: bool,
     *     duplicate_sensitive?: bool,
     *     compare_nulls?: bool,
     *     compare_schema?: bool,
     *     nested_order_sensitive?: bool,
     *     aggregates?: list<array{field: string, function: string}>,
     *     aggregate_fields?: list<string>,
     *     aggregate_paths?: list<string>
     * }  $options
     * @return array<string, mixed>
     */
    public function compare(array $expected, array $actual, array $options = []): array
    {
        return $this->compareProfiles(
            $this->profile($expected, $options),
            $this->profile($actual, $options),
        );
    }

    /**
     * Build the only representation that may be persisted for later checks.
     * `row_hashes` intentionally preserves sequence even when order is not a
     * requirement: this lets the report state whether order changed without
     * making it a failure unless `order_sensitive` is true.
     *
     * @param  list<array<array-key, mixed>>  $dataset
     * @param  array{
     *     order_sensitive?: bool,
     *     duplicate_sensitive?: bool,
     *     compare_nulls?: bool,
     *     compare_schema?: bool,
     *     nested_order_sensitive?: bool,
     *     aggregates?: list<array{field: string, function: string}>,
     *     aggregate_fields?: list<string>,
     *     aggregate_paths?: list<string>
     * }  $options
     * @return array{
     *     profile_version: int,
     *     hmac_version: string,
     *     dataset_hash: string,
     *     config_hash: string,
     *     order_sensitive: bool,
     *     duplicate_sensitive: bool,
     *     compare_nulls: bool,
     *     compare_schema: bool,
     *     nested_order_sensitive: bool,
     *     row_count: int,
     *     row_hashes: list<string>,
     *     row_counts: list<array{hash: string, count: int}>,
     *     schema_counts: list<array{hash: string, count: int}>,
     *     duplicate_counts: list<array{hash: string, count: int}>,
     *     null_counts: list<array{hash: string, count: int}>,
     *     nested_counts: list<array{hash: string, count: int}>,
     *     aggregate_hashes: list<string>,
     *     aggregate_counts: list<array{hash: string, count: int}>,
     *     aggregate_count: int,
     *     null_count: int
     * }
     */
    public function profile(array $dataset, array $options = []): array
    {
        $this->assertDataset($dataset, 'dataset');
        $normalized = $this->normalizeOptions($options);
        $nestedOrderSensitive = $normalized['nested_order_sensitive'];
        $comparisonDataset = $normalized['compare_nulls']
            ? $dataset
            : array_map(fn (array $row): array => $this->withoutNulls($row), $dataset);
        $rowHashes = array_map(
            fn (array $row): string => $this->hmac(
                'row-value',
                $this->canonicalize($row, $nestedOrderSensitive),
            ),
            $comparisonDataset,
        );
        $schemaHashes = array_map(
            fn (array $row): string => $this->hmac('schema-shape', $this->schemaShape($row)),
            $dataset,
        );
        $rowCounts = $this->counts($rowHashes);
        $nullCounts = $this->safeProfile(
            $this->nullProfile($dataset),
            'null-occurrence',
        );
        $nestedCounts = $this->safeProfile(
            $this->nestedProfile($comparisonDataset, $nestedOrderSensitive),
            'nested-value',
        );
        $aggregateHashes = array_map(
            fn (string $token): string => $this->hmac('aggregate-value', $token),
            $this->aggregateTokens(
                $comparisonDataset,
                $normalized['aggregates'],
                $normalized['order_sensitive'],
                $nestedOrderSensitive,
            ),
        );

        $profile = [
            'profile_version' => self::PROFILE_VERSION,
            'hmac_version' => $this->hmacConfiguration()[1],
            'config_hash' => $this->hmac('comparison-config', $this->canonicalize($normalized)),
            'order_sensitive' => $normalized['order_sensitive'],
            'duplicate_sensitive' => $normalized['duplicate_sensitive'],
            'compare_nulls' => $normalized['compare_nulls'],
            'compare_schema' => $normalized['compare_schema'],
            'nested_order_sensitive' => $nestedOrderSensitive,
            'row_count' => count($dataset),
            'row_hashes' => $rowHashes,
            'row_counts' => $this->encodeCounts($rowCounts),
            'schema_counts' => $this->encodeCounts($this->counts($schemaHashes)),
            'duplicate_counts' => $this->encodeCounts($this->duplicateProfile($rowCounts)),
            'null_counts' => $this->encodeCounts($nullCounts),
            'nested_counts' => $this->encodeCounts($nestedCounts),
            'aggregate_hashes' => $aggregateHashes,
            'aggregate_counts' => $this->encodeCounts($this->counts($aggregateHashes)),
            'aggregate_count' => count($normalized['aggregates']),
            'null_count' => array_sum($nullCounts),
        ];
        $profile['dataset_hash'] = $this->hmac(
            'dataset-profile',
            $this->canonicalize($profile),
        );

        // Keep the digest near the profile metadata in serialized JSON.
        return [
            'profile_version' => $profile['profile_version'],
            'hmac_version' => $profile['hmac_version'],
            'dataset_hash' => $profile['dataset_hash'],
            'config_hash' => $profile['config_hash'],
            'order_sensitive' => $profile['order_sensitive'],
            'duplicate_sensitive' => $profile['duplicate_sensitive'],
            'compare_nulls' => $profile['compare_nulls'],
            'compare_schema' => $profile['compare_schema'],
            'nested_order_sensitive' => $profile['nested_order_sensitive'],
            'row_count' => $profile['row_count'],
            'row_hashes' => $profile['row_hashes'],
            'row_counts' => $profile['row_counts'],
            'schema_counts' => $profile['schema_counts'],
            'duplicate_counts' => $profile['duplicate_counts'],
            'null_counts' => $profile['null_counts'],
            'nested_counts' => $profile['nested_counts'],
            'aggregate_hashes' => $profile['aggregate_hashes'],
            'aggregate_counts' => $profile['aggregate_counts'],
            'aggregate_count' => $profile['aggregate_count'],
            'null_count' => $profile['null_count'],
        ];
    }

    /**
     * Compare a persisted safe profile with a new in-memory result.
     *
     * @param  array<string, mixed>  $expectedProfile
     * @param  list<array<array-key, mixed>>  $actual
     * @param  array{
     *     order_sensitive?: bool,
     *     duplicate_sensitive?: bool,
     *     compare_nulls?: bool,
     *     compare_schema?: bool,
     *     nested_order_sensitive?: bool,
     *     aggregates?: list<array{field: string, function: string}>,
     *     aggregate_fields?: list<string>,
     *     aggregate_paths?: list<string>
     * }  $options
     * @return array<string, mixed>
     */
    public function compareProfile(array $expectedProfile, array $actual, array $options = []): array
    {
        return $this->compareProfiles(
            $expectedProfile,
            $this->profile($actual, $options),
        );
    }

    /**
     * Compare two safe profiles. This is public for offline verification and
     * never requires access to the original rows.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @return array{
     *     equivalent: bool,
     *     expected_fingerprint: string,
     *     actual_fingerprint: string,
     *     schema_match: bool,
     *     values_match: bool,
     *     order_match: bool,
     *     order_required: bool,
     *     duplicate_match: bool,
     *     null_match: bool,
     *     aggregate_match: bool,
     *     nested_match: bool,
     *     mismatches: array{
     *         schema: int,
     *         missing_rows: int,
     *         unexpected_rows: int,
     *         order_positions: int,
     *         duplicate_groups: int,
     *         null_occurrences: int,
     *         aggregate_values: int,
     *         nested_values: int
     *     },
     *     counts: array{
     *         expected_rows: int,
     *         actual_rows: int,
     *         expected_duplicate_groups: int,
     *         actual_duplicate_groups: int,
     *         expected_nulls: int,
     *         actual_nulls: int,
     *         aggregates: int
     *     }
     * }
     */
    public function compareProfiles(array $expected, array $actual): array
    {
        $this->assertProfile($expected);
        $this->assertProfile($actual);

        if (! hash_equals($expected['config_hash'], $actual['config_hash'])) {
            throw new InvalidArgumentException('Reference and actual comparison configurations differ.');
        }

        $expectedRows = $this->decodeCounts($expected['row_counts']);
        $actualRows = $this->decodeCounts($actual['row_counts']);
        $expectedValues = $expected['duplicate_sensitive']
            ? $expectedRows
            : array_fill_keys(array_keys($expectedRows), 1);
        $actualValues = $actual['duplicate_sensitive']
            ? $actualRows
            : array_fill_keys(array_keys($actualRows), 1);
        [$missingRows, $unexpectedRows] = $this->multisetDifference($expectedValues, $actualValues);
        $schemaMismatch = $this->profileDistance(
            $this->decodeCounts($expected['schema_counts']),
            $this->decodeCounts($actual['schema_counts']),
        );
        $orderMismatch = $this->orderedMismatchCount(
            $expected['row_hashes'],
            $actual['row_hashes'],
        );
        $duplicateMismatch = $this->profileDistance(
            $this->decodeCounts($expected['duplicate_counts']),
            $this->decodeCounts($actual['duplicate_counts']),
        );
        $nullMismatch = $this->profileDistance(
            $this->decodeCounts($expected['null_counts']),
            $this->decodeCounts($actual['null_counts']),
        );
        $nestedMismatch = $this->profileDistance(
            $this->decodeCounts($expected['nested_counts']),
            $this->decodeCounts($actual['nested_counts']),
        );
        $aggregateMismatch = $expected['order_sensitive']
            ? $this->orderedMismatchCount($expected['aggregate_hashes'], $actual['aggregate_hashes'])
            : $this->profileDistance(
                $this->decodeCounts($expected['aggregate_counts']),
                $this->decodeCounts($actual['aggregate_counts']),
            );

        $schemaMatch = $schemaMismatch === 0;
        $valuesMatch = $missingRows === 0 && $unexpectedRows === 0;
        $orderMatch = $orderMismatch === 0;
        $duplicateMatch = $duplicateMismatch === 0;
        $nullMatch = $nullMismatch === 0;
        $aggregateMatch = $aggregateMismatch === 0;
        $nestedMatch = $nestedMismatch === 0;
        $equivalent = (! $expected['compare_schema'] || $schemaMatch)
            && $valuesMatch
            && (! $expected['duplicate_sensitive'] || $duplicateMatch)
            && (! $expected['compare_nulls'] || $nullMatch)
            && $aggregateMatch
            && $nestedMatch
            && (! $expected['order_sensitive'] || $orderMatch);

        return [
            'equivalent' => $equivalent,
            'expected_fingerprint' => $expected['dataset_hash'],
            'actual_fingerprint' => $actual['dataset_hash'],
            'schema_match' => $schemaMatch,
            'schema_required' => $expected['compare_schema'],
            'values_match' => $valuesMatch,
            'order_match' => $orderMatch,
            'order_required' => $expected['order_sensitive'],
            'duplicate_match' => $duplicateMatch,
            'duplicates_required' => $expected['duplicate_sensitive'],
            'null_match' => $nullMatch,
            'nulls_required' => $expected['compare_nulls'],
            'aggregate_match' => $aggregateMatch,
            'nested_match' => $nestedMatch,
            'mismatches' => [
                'schema' => $schemaMismatch,
                'missing_rows' => $missingRows,
                'unexpected_rows' => $unexpectedRows,
                'order_positions' => $orderMismatch,
                'duplicate_groups' => $duplicateMismatch,
                'null_occurrences' => $nullMismatch,
                'aggregate_values' => $aggregateMismatch,
                'nested_values' => $nestedMismatch,
            ],
            'counts' => [
                'expected_rows' => $expected['row_count'],
                'actual_rows' => $actual['row_count'],
                'expected_duplicate_groups' => count($expected['duplicate_counts']),
                'actual_duplicate_groups' => count($actual['duplicate_counts']),
                'expected_nulls' => $expected['null_count'],
                'actual_nulls' => $actual['null_count'],
                'aggregates' => $expected['aggregate_count'],
            ],
        ];
    }

    /**
     * @param  list<array<array-key, mixed>>  $dataset
     * @param  array<string, mixed>  $options
     */
    public function fingerprint(array $dataset, array $options = []): string
    {
        return $this->profile($dataset, $options)['dataset_hash'];
    }

    /**
     * Return the strict, complete configuration persisted with a profile.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     order_sensitive: bool,
     *     duplicate_sensitive: bool,
     *     compare_nulls: bool,
     *     compare_schema: bool,
     *     nested_order_sensitive: bool,
     *     aggregates: list<array{field: string, function: string}>
     * }
     */
    public function comparisonConfig(array $options = []): array
    {
        return $this->normalizeOptions($options);
    }

    /**
     * Assertion order, map key order, field order and allowed-value order do
     * not change this deterministic rule-set fingerprint.
     *
     * @param  array<array-key, mixed>  $rules
     */
    public function rulesFingerprint(array $rules): string
    {
        if (! array_is_list($rules)) {
            throw new InvalidArgumentException('Data-quality rules must be a list.');
        }

        $tokens = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('Each data-quality rule must be an object.');
            }

            $tokens[] = $this->canonicalize($rule, false);
        }

        sort($tokens, SORT_STRING);

        return $this->hmac('data-quality-rules', $this->canonicalize($tokens));
    }

    /** Fingerprint one in-memory fragment for evaluator set operations. */
    public function fingerprintValue(mixed $value, string $domain): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $domain) !== 1) {
            throw new InvalidArgumentException('The fingerprint domain is invalid.');
        }

        return $this->hmac('fragment/'.$domain, $this->canonicalize($value));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     order_sensitive: bool,
     *     duplicate_sensitive: bool,
     *     compare_nulls: bool,
     *     compare_schema: bool,
     *     nested_order_sensitive: bool,
     *     aggregates: list<array{field: string, function: string}>
     * }
     */
    private function normalizeOptions(array $options): array
    {
        $unknown = array_diff(
            array_keys($options),
            [
                'order_sensitive', 'duplicate_sensitive', 'compare_nulls',
                'compare_schema', 'nested_order_sensitive', 'aggregates',
                'aggregate_fields', 'aggregate_paths',
            ],
        );

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown dataset comparison option.');
        }

        $aggregateFields = $options['aggregate_fields'] ?? $options['aggregate_paths'] ?? [];

        if (! is_array($aggregateFields) || ! array_is_list($aggregateFields)) {
            throw new InvalidArgumentException('Aggregate fields must be a list.');
        }

        $normalizedAggregates = [];

        foreach ($aggregateFields as $field) {
            if (! is_string($field) || ! $this->isSafePath($field)) {
                throw new InvalidArgumentException('An aggregate field path is invalid.');
            }

            $normalizedAggregates[] = ['field' => trim($field), 'function' => 'value'];
        }

        $aggregates = $options['aggregates'] ?? [];

        if (! is_array($aggregates) || ! array_is_list($aggregates)) {
            throw new InvalidArgumentException('Aggregates must be a list.');
        }

        foreach ($aggregates as $aggregate) {
            if (! is_array($aggregate)
                || ! is_string($aggregate['field'] ?? null)
                || ! $this->isSafePath($aggregate['field'])
                || ! is_string($aggregate['function'] ?? null)) {
                throw new InvalidArgumentException('An aggregate comparison is invalid.');
            }

            $function = mb_strtolower(trim($aggregate['function']));

            if (! in_array($function, ['value', 'count', 'distinct_count', 'sum', 'min', 'max', 'avg'], true)) {
                throw new InvalidArgumentException('An aggregate function is invalid.');
            }

            $normalizedAggregates[] = [
                'field' => trim($aggregate['field']),
                'function' => $function,
            ];
        }

        $uniqueAggregates = [];

        foreach ($normalizedAggregates as $aggregate) {
            $uniqueAggregates[$aggregate['function']."\0".$aggregate['field']] = $aggregate;
        }

        ksort($uniqueAggregates, SORT_STRING);

        return [
            'order_sensitive' => $this->booleanOption($options, 'order_sensitive', true),
            'duplicate_sensitive' => $this->booleanOption($options, 'duplicate_sensitive', true),
            'compare_nulls' => $this->booleanOption($options, 'compare_nulls', true),
            'compare_schema' => $this->booleanOption($options, 'compare_schema', true),
            'nested_order_sensitive' => $this->booleanOption($options, 'nested_order_sensitive', true),
            'aggregates' => array_values($uniqueAggregates),
        ];
    }

    /** @param array<string, mixed> $options */
    private function booleanOption(array $options, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $options)) {
            return $default;
        }

        if (! is_bool($options[$key])) {
            throw new InvalidArgumentException("The [{$key}] comparison option must be boolean.");
        }

        return $options[$key];
    }

    /** @param array<array-key, mixed> $dataset */
    private function assertDataset(array $dataset, string $side): void
    {
        if (! array_is_list($dataset)) {
            throw new InvalidArgumentException("The {$side} dataset must be a list of rows.");
        }

        foreach ($dataset as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException("Every {$side} dataset row must be an object.");
            }
        }
    }

    /** @param array<string, mixed> $profile */
    private function assertProfile(array $profile): void
    {
        $required = [
            'profile_version', 'hmac_version', 'dataset_hash', 'config_hash', 'order_sensitive',
            'duplicate_sensitive', 'compare_nulls', 'compare_schema',
            'nested_order_sensitive', 'row_count', 'row_hashes', 'row_counts',
            'schema_counts', 'duplicate_counts', 'null_counts', 'nested_counts',
            'aggregate_hashes', 'aggregate_counts', 'aggregate_count', 'null_count',
        ];

        if (array_diff($required, array_keys($profile)) !== []
            || $profile['profile_version'] !== self::PROFILE_VERSION
            || ! is_string($profile['hmac_version'])
            || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $profile['hmac_version']) !== 1
            || ! is_bool($profile['order_sensitive'])
            || ! is_bool($profile['duplicate_sensitive'])
            || ! is_bool($profile['compare_nulls'])
            || ! is_bool($profile['compare_schema'])
            || ! is_bool($profile['nested_order_sensitive'])
            || ! is_int($profile['row_count'])
            || $profile['row_count'] < 0
            || ! is_int($profile['aggregate_count'])
            || $profile['aggregate_count'] < 0
            || ! is_int($profile['null_count'])
            || $profile['null_count'] < 0) {
            throw new InvalidArgumentException('The dataset profile is invalid.');
        }

        $this->assertHash($profile['dataset_hash']);
        $this->assertHash($profile['config_hash']);

        foreach (['row_hashes', 'aggregate_hashes'] as $key) {
            if (! is_array($profile[$key]) || ! array_is_list($profile[$key])) {
                throw new InvalidArgumentException('The dataset profile is invalid.');
            }

            foreach ($profile[$key] as $hash) {
                $this->assertHash($hash);
            }
        }

        $unsigned = $profile;
        unset($unsigned['dataset_hash']);
        $computed = $this->hmac('dataset-profile', $this->canonicalize($unsigned));

        if (! hash_equals($profile['dataset_hash'], $computed)) {
            throw new InvalidArgumentException('The dataset profile signature is invalid.');
        }

        if (count($profile['row_hashes']) !== $profile['row_count']) {
            throw new InvalidArgumentException('The dataset profile row count is inconsistent.');
        }

        foreach (['row_counts', 'schema_counts', 'duplicate_counts', 'null_counts', 'nested_counts', 'aggregate_counts'] as $key) {
            if (! is_array($profile[$key]) || ! array_is_list($profile[$key])) {
                throw new InvalidArgumentException('The dataset profile is invalid.');
            }

            $this->decodeCounts($profile[$key]);
        }

    }

    private function assertHash(mixed $hash): string
    {
        if (! is_string($hash)
            || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException('A dataset profile fingerprint is invalid.');
        }

        return $hash;
    }

    /** Canonicalize a JSON-compatible value using unambiguous type markers. */
    private function canonicalize(mixed $value, bool $listOrderSensitive = true): string
    {
        if ($value === null) {
            return 'null:0:';
        }

        if (is_bool($value)) {
            return $this->pack('bool', $value ? '1' : '0');
        }

        if (is_int($value)) {
            return $this->pack('int', (string) $value);
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Datasets cannot contain non-finite numbers.');
            }

            try {
                $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('A dataset number cannot be canonicalized.', previous: $exception);
            }

            return $this->pack('float', $encoded);
        }

        if (is_string($value)) {
            return $this->pack('string', $value);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('Datasets may only contain JSON-compatible values.');
        }

        if (array_is_list($value)) {
            $items = array_map(
                fn (mixed $item): string => $this->canonicalize($item, $listOrderSensitive),
                $value,
            );

            if (! $listOrderSensitive) {
                sort($items, SORT_STRING);
            }

            return $this->pack('list', implode('', array_map(
                fn (string $item): string => $this->pack('item', $item),
                $items,
            )));
        }

        $entries = [];

        foreach ($value as $key => $item) {
            $canonicalKey = is_int($key)
                ? $this->pack('int-key', (string) $key)
                : $this->pack('string-key', $key);
            $entries[$canonicalKey] = $this->canonicalize($item, $listOrderSensitive);
        }

        ksort($entries, SORT_STRING);
        $payload = '';

        foreach ($entries as $key => $item) {
            $payload .= $this->pack('entry-key', $key).$this->pack('entry-value', $item);
        }

        return $this->pack('map', $payload);
    }

    private function pack(string $type, string $payload): string
    {
        return $type.':'.strlen($payload).':'.$payload;
    }

    /** @param array<array-key, mixed> $value */
    private function schemaShape(array $value): string
    {
        if (array_is_list($value)) {
            $shapes = array_map(fn (mixed $item): string => $this->valueSchemaShape($item), $value);
            $shapes = array_values(array_unique($shapes));
            sort($shapes, SORT_STRING);

            return $this->pack('schema-list', implode('', array_map(
                fn (string $shape): string => $this->pack('shape', $shape),
                $shapes,
            )));
        }

        $entries = [];

        foreach ($value as $key => $item) {
            $canonicalKey = is_int($key)
                ? $this->pack('int-key', (string) $key)
                : $this->pack('string-key', $key);
            $entries[$canonicalKey] = $this->valueSchemaShape($item);
        }

        ksort($entries, SORT_STRING);
        $payload = '';

        foreach ($entries as $key => $shape) {
            $payload .= $this->pack('schema-key', $key).$this->pack('schema-value', $shape);
        }

        return $this->pack('schema-map', $payload);
    }

    private function valueSchemaShape(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) => $this->schemaShape($value),
            default => throw new InvalidArgumentException('Datasets may only contain JSON-compatible values.'),
        };
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, int>
     */
    private function counts(array $tokens): array
    {
        $counts = [];

        foreach ($tokens as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{hash: string, count: int}>
     */
    private function encodeCounts(array $counts): array
    {
        ksort($counts, SORT_STRING);
        $encoded = [];

        foreach ($counts as $hash => $count) {
            $encoded[] = ['hash' => $hash, 'count' => $count];
        }

        return $encoded;
    }

    /**
     * @param  array<array-key, mixed>  $encoded
     * @return array<string, int>
     */
    private function decodeCounts(array $encoded): array
    {
        $counts = [];

        foreach ($encoded as $entry) {
            if (! is_array($entry)
                || array_keys($entry) !== ['hash', 'count']
                || ! is_int($entry['count'])
                || $entry['count'] < 1) {
                throw new InvalidArgumentException('A dataset profile count is invalid.');
            }

            $hash = $this->assertHash($entry['hash']);

            if (isset($counts[$hash])) {
                throw new InvalidArgumentException('A dataset profile contains a duplicate hash.');
            }

            $counts[$hash] = $entry['count'];
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @param  array<string, int>  $profile
     * @return array<string, int>
     */
    private function safeProfile(array $profile, string $domain): array
    {
        $safe = [];

        foreach ($profile as $token => $count) {
            $hash = $this->hmac($domain, $token);
            $safe[$hash] = ($safe[$hash] ?? 0) + $count;
        }

        ksort($safe, SORT_STRING);

        return $safe;
    }

    /**
     * @param  array<string, int>  $rowCounts
     * @return array<string, int>
     */
    private function duplicateProfile(array $rowCounts): array
    {
        return array_filter($rowCounts, fn (int $count): bool => $count > 1);
    }

    /**
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $actual
     * @return array{int, int}
     */
    private function multisetDifference(array $expected, array $actual): array
    {
        $missing = 0;
        $unexpected = 0;

        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $token) {
            $difference = ($expected[$token] ?? 0) - ($actual[$token] ?? 0);

            if ($difference > 0) {
                $missing += $difference;
            } elseif ($difference < 0) {
                $unexpected += abs($difference);
            }
        }

        return [$missing, $unexpected];
    }

    /**
     * @param  array<string, int>  $left
     * @param  array<string, int>  $right
     */
    private function profileDistance(array $left, array $right): int
    {
        $distance = 0;

        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $distance += abs(($left[$key] ?? 0) - ($right[$key] ?? 0));
        }

        return $distance;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function orderedMismatchCount(array $expected, array $actual): int
    {
        $count = max(count($expected), count($actual));
        $mismatches = 0;

        for ($index = 0; $index < $count; $index++) {
            if (($expected[$index] ?? null) !== ($actual[$index] ?? null)) {
                $mismatches++;
            }
        }

        return $mismatches;
    }

    /**
     * @param  list<array<array-key, mixed>>  $dataset
     * @return array<string, int>
     */
    private function nullProfile(array $dataset): array
    {
        $profile = [];

        foreach ($dataset as $row) {
            $this->collectNulls($row, '$', $profile);
        }

        ksort($profile, SORT_STRING);

        return $profile;
    }

    /** @param array<string, int> $profile */
    private function collectNulls(mixed $value, string $path, array &$profile): void
    {
        if ($value === null) {
            $profile[$path] = ($profile[$path] ?? 0) + 1;

            return;
        }

        if (! is_array($value)) {
            return;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $this->collectNulls($item, $path.'[]', $profile);
            }

            return;
        }

        foreach ($value as $key => $item) {
            $this->collectNulls($item, $path.'.'.$this->pathSegment($key), $profile);
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $dataset
     * @return array<string, int>
     */
    private function nestedProfile(array $dataset, bool $nestedOrderSensitive): array
    {
        $profile = [];

        foreach ($dataset as $row) {
            $this->collectNested($row, '$', $profile, $nestedOrderSensitive, false);
        }

        ksort($profile, SORT_STRING);

        return $profile;
    }

    /** @param array<string, int> $profile */
    private function collectNested(
        mixed $value,
        string $path,
        array &$profile,
        bool $nestedOrderSensitive,
        bool $record,
    ): void {
        if (! is_array($value)) {
            return;
        }

        if ($record) {
            $token = $this->pack('path', $path).$this->pack(
                'value',
                $this->canonicalize($value, $nestedOrderSensitive),
            );
            $profile[$token] = ($profile[$token] ?? 0) + 1;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $this->collectNested($item, $path.'[]', $profile, $nestedOrderSensitive, true);
            }

            return;
        }

        foreach ($value as $key => $item) {
            $this->collectNested(
                $item,
                $path.'.'.$this->pathSegment($key),
                $profile,
                $nestedOrderSensitive,
                true,
            );
        }
    }

    /**
     * @param  list<array<array-key, mixed>>  $dataset
     * @param  list<array{field: string, function: string}>  $aggregates
     * @return list<string>
     */
    private function aggregateTokens(
        array $dataset,
        array $aggregates,
        bool $orderSensitive,
        bool $nestedOrderSensitive,
    ): array {
        if ($aggregates === []) {
            return [];
        }

        $tokens = [];

        foreach ($aggregates as $aggregate) {
            $values = [];

            foreach ($dataset as $row) {
                [$found, $value] = $this->valueAtPath($row, $aggregate['field']);
                $values[] = $found ? $value : ['__oracle_data_missing__' => true];
            }

            $result = $this->aggregateValue(
                $values,
                $aggregate['function'],
                $orderSensitive,
                $nestedOrderSensitive,
            );
            $tokens[] = $this->canonicalize([
                'function' => $aggregate['function'],
                'result' => $result,
            ], $nestedOrderSensitive);
        }

        return $tokens;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function aggregateValue(
        array $values,
        string $function,
        bool $orderSensitive,
        bool $nestedOrderSensitive,
    ): mixed {
        if ($function === 'value') {
            if (! $orderSensitive) {
                usort(
                    $values,
                    fn (mixed $left, mixed $right): int => $this->canonicalize($left, $nestedOrderSensitive)
                        <=> $this->canonicalize($right, $nestedOrderSensitive),
                );
            }

            return $values;
        }

        $present = array_values(array_filter(
            $values,
            fn (mixed $value): bool => ! (is_array($value)
                && $value === ['__oracle_data_missing__' => true])
                && $value !== null,
        ));

        if ($function === 'count') {
            return count($present);
        }

        if ($function === 'distinct_count') {
            $distinct = [];

            foreach ($present as $value) {
                $distinct[$this->canonicalize($value, $nestedOrderSensitive)] = true;
            }

            return count($distinct);
        }

        foreach ($present as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('Numeric aggregates require numeric field values.');
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Numeric aggregates require finite field values.');
            }
        }

        if ($present === []) {
            return null;
        }

        return match ($function) {
            'sum' => array_sum($present),
            'min' => min($present),
            'max' => max($present),
            'avg' => array_sum($present) / count($present),
            default => throw new InvalidArgumentException('An aggregate function is invalid.'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array{bool, mixed}
     */
    private function valueAtPath(array $row, string $path): array
    {
        $value = $row;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * Remove explicit nulls solely from the in-memory comparison projection.
     * The original dataset still feeds the null and schema profiles, allowing
     * those dimensions to be reported even when `compare_nulls` is disabled.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function withoutNulls(array $value): array
    {
        if (array_is_list($value)) {
            $items = [];

            foreach ($value as $item) {
                if ($item === null) {
                    continue;
                }

                $items[] = is_array($item) ? $this->withoutNulls($item) : $item;
            }

            return $items;
        }

        $items = [];

        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }

            $items[$key] = is_array($item) ? $this->withoutNulls($item) : $item;
        }

        return $items;
    }

    private function isSafePath(string $path): bool
    {
        $path = trim($path);

        return $path !== ''
            && mb_strlen($path) <= 500
            && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*$/', $path) === 1;
    }

    private function pathSegment(int|string $key): string
    {
        return is_int($key) ? '#'.$key : rawurlencode($key);
    }

    private function hmac(string $domain, string $payload): string
    {
        [$key, $version] = $this->hmacConfiguration();
        $message = implode("\0", [
            'oracle-data/data-quality',
            $version,
            $domain,
            $payload,
        ]);

        return hash_hmac('sha256', $message, $key);
    }

    /** @return array{string, string} */
    private function hmacConfiguration(): array
    {
        $version = trim($this->configuredHmacVersion
            ?? (string) config('audit.data_quality.hmac.version', 'v1'));
        $key = $this->configuredHmacKey
            ?? (string) config('audit.data_quality.hmac.key', '');

        if ($version === '' || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version) !== 1) {
            throw new LogicException('The data-quality HMAC version is invalid.');
        }

        if ($key === '') {
            $appKey = (string) config('app.key', '');

            if ($appKey === '') {
                throw new LogicException('The data-quality HMAC key is unavailable.');
            }

            $key = hash_hmac('sha256', self::KEY_DERIVATION_DOMAIN, $appKey, true);
        }

        return [$key, $version];
    }
}
