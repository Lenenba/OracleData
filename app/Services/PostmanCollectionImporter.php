<?php

namespace App\Services;

use App\Rules\AllowedResourcePath;
use Illuminate\Support\Str;

/**
 * Turns a Postman collection into safe, read-only OracleData query candidates.
 *
 * Only the relative Oracle resource path and the supported GET parameters are
 * returned. Hosts, credentials, headers, bodies, scripts and examples never
 * leave the parser and are never persisted by the import workflow.
 *
 * @phpstan-type ImportParameters array{q?: string, expand?: string, fields?: string, orderBy?: string, limit?: int, offset?: int}
 * @phpstan-type ImportCandidate array{
 *     name: string,
 *     folder: string|null,
 *     resource_path: string,
 *     parameters: ImportParameters,
 *     semantic_resource_key: string|null,
 *     warnings: list<string>,
 *     tenant_specific_path: bool
 * }
 */
class PostmanCollectionImporter
{
    private const int MAX_DEPTH = 20;

    private const int MAX_ITEMS = 1000;

    private const int MAX_CANDIDATES = 500;

    /** @var array<string, int> */
    private const array STRING_PARAMETER_LIMITS = [
        'q' => 2000,
        'expand' => 500,
        'fields' => 2000,
        'orderBy' => 500,
    ];

    /** @var array<string, string> */
    private array $semanticResources = [];

    private int $visited = 0;

    private bool $truncated = false;

    /** @var array<string, true> */
    private array $fingerprints = [];

    public function __construct(OracleResourceCatalog $catalog)
    {
        foreach ($catalog->all() as $resource) {
            if (preg_match('#^/(hcm|fscm)RestApi/resources/(?:latest|\d+(?:\.\d+){3})/([^/]+)#', $resource['path'], $matches) === 1) {
                $this->semanticResources[mb_strtolower($matches[1].':'.$matches[2])] = $resource['key'];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $collection
     * @return array{
     *     collection: array{name: string},
     *     importable: list<ImportCandidate>,
     *     skipped: array{writes: int, describe: int, other: int, variables: int, duplicates: int, invalid_path: int, too_long: int, invalid_parameters: int},
     *     scanned: int,
     *     truncated: bool,
     *     security: array{oracle_calls: false, method: string, ignored: list<string>}
     * }
     */
    public function parse(array $collection): array
    {
        $this->visited = 0;
        $this->truncated = false;
        $this->fingerprints = [];

        $importable = [];
        $skipped = $this->emptySkippedCounts();
        $items = is_array($collection['item'] ?? null) ? $collection['item'] : [];

        $this->walk($items, $importable, $skipped, [], 0);

        return [
            'collection' => [
                'name' => $this->cleanName($collection['info']['name'] ?? null, 'Collection Postman'),
            ],
            'importable' => $importable,
            'skipped' => $skipped,
            'scanned' => $this->visited,
            'truncated' => $this->truncated,
            'security' => [
                'oracle_calls' => false,
                'method' => 'GET',
                'ignored' => ['host', 'authentication', 'headers', 'body', 'scripts', 'examples'],
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  list<ImportCandidate>  $importable
     * @param  array{writes: int, describe: int, other: int, variables: int, duplicates: int, invalid_path: int, too_long: int, invalid_parameters: int}  $skipped
     * @param  list<string>  $folders
     */
    private function walk(array $items, array &$importable, array &$skipped, array $folders, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->truncated = true;

            return;
        }

        foreach ($items as $item) {
            if ($this->visited >= self::MAX_ITEMS || count($importable) >= self::MAX_CANDIDATES) {
                $this->truncated = true;

                return;
            }

            $this->visited++;

            if (! is_array($item)) {
                $skipped['other']++;

                continue;
            }

            if (is_array($item['item'] ?? null)) {
                $folderName = $this->cleanName($item['name'] ?? null, 'Dossier');
                $this->walk($item['item'], $importable, $skipped, [...$folders, $folderName], $depth + 1);

                if ($this->truncated) {
                    return;
                }

                continue;
            }

            $request = $item['request'] ?? null;

            if (! is_array($request)) {
                $skipped['other']++;

                continue;
            }

            $method = strtoupper(trim((string) ($request['method'] ?? '')));

            if ($method === '') {
                $skipped['other']++;

                continue;
            }

            if ($method !== 'GET') {
                $skipped['writes']++;

                continue;
            }

            $path = $this->extractPath($request['url'] ?? null);

            if ($path === null) {
                $skipped['other']++;

                continue;
            }

            if (str_ends_with(mb_strtolower(rtrim($path, '/')), '/describe')) {
                $skipped['describe']++;

                continue;
            }

            if ($this->containsVariable($path)) {
                $skipped['variables']++;

                continue;
            }

            if (mb_strlen($path) > AllowedResourcePath::MAX_LENGTH) {
                $skipped['too_long']++;

                continue;
            }

            if (! AllowedResourcePath::isAllowed($path)) {
                $skipped['invalid_path']++;

                continue;
            }

            $parameterResult = $this->extractParameters($request['url'] ?? null);

            if ($parameterResult['contains_variable']) {
                $skipped['variables']++;

                continue;
            }

            $skipped['invalid_parameters'] += $parameterResult['invalid'];
            $warnings = [];

            if ($parameterResult['unsupported'] > 0) {
                $warnings[] = 'unsupported_parameters_ignored';
            }

            if ($parameterResult['invalid'] > 0) {
                $warnings[] = 'invalid_parameters_ignored';
            }

            if ($this->hasSourceHost($request['url'] ?? null)) {
                $warnings[] = 'source_host_removed';
            }

            $tenantSpecificPath = $this->isTenantSpecificPath($path);

            if ($tenantSpecificPath) {
                $warnings[] = 'tenant_specific_path';
            }

            $fingerprint = $this->fingerprint($path, $parameterResult['parameters']);

            if (isset($this->fingerprints[$fingerprint])) {
                $skipped['duplicates']++;

                continue;
            }

            $this->fingerprints[$fingerprint] = true;
            $importable[] = [
                'name' => $this->cleanName($item['name'] ?? null, $path),
                'folder' => $folders === [] ? null : Str::limit(implode(' / ', $folders), 255, ''),
                'resource_path' => $path,
                'parameters' => $parameterResult['parameters'],
                'semantic_resource_key' => $this->semanticResourceKey($path),
                'warnings' => $warnings,
                'tenant_specific_path' => $tenantSpecificPath,
            ];
        }
    }

    private function extractPath(mixed $url): ?string
    {
        $segments = is_array($url) ? ($url['path'] ?? null) : null;

        if (is_array($segments) && $segments !== []) {
            $parts = [];

            foreach ($segments as $segment) {
                if (! is_string($segment) && ! is_int($segment)) {
                    return null;
                }

                $part = trim((string) $segment, '/');

                if ($part === '') {
                    return null;
                }

                $parts[] = $part;
            }

            $path = '/'.implode('/', $parts);
        } else {
            $raw = is_array($url) ? (string) ($url['raw'] ?? '') : (string) $url;
            $path = $this->pathFromRaw($raw);
        }

        $queryPosition = strpos($path, '?');

        if ($queryPosition !== false) {
            $path = substr($path, 0, $queryPosition);
        }

        return $path === '' || $path === '/' ? null : $path;
    }

    private function pathFromRaw(string $raw): string
    {
        // Find only the two allowlisted Oracle APIs and deliberately discard
        // everything before them, including an absolute or variable host.
        if (preg_match('~/(?:hcm|fscm)RestApi/[^?#]*~', $raw, $matches) === 1) {
            return $matches[0];
        }

        $path = parse_url($raw, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * @return array{parameters: ImportParameters, invalid: int, unsupported: int, contains_variable: bool}
     */
    private function extractParameters(mixed $url): array
    {
        $query = is_array($url) ? ($url['query'] ?? null) : null;
        $pairs = [];
        $unsupported = 0;
        $invalid = 0;

        if (is_array($query)) {
            foreach ($query as $entry) {
                if (! is_array($entry) || ($entry['disabled'] ?? false) === true) {
                    continue;
                }

                $key = trim((string) ($entry['key'] ?? ''));
                $value = $entry['value'] ?? '';

                if ($key === '' || $value === '') {
                    continue;
                }

                if (! array_key_exists($key, self::STRING_PARAMETER_LIMITS) && ! in_array($key, ['limit', 'offset'], true)) {
                    $unsupported++;

                    continue;
                }

                if (! is_scalar($value)) {
                    $invalid++;

                    continue;
                }

                $pairs[$key] = (string) $value;
            }
        } else {
            $raw = is_array($url) ? (string) ($url['raw'] ?? '') : (string) $url;
            $rawQuery = parse_url($raw, PHP_URL_QUERY);
            $parsed = [];

            if (is_string($rawQuery)) {
                parse_str($rawQuery, $parsed);
            }

            foreach ($parsed as $key => $value) {
                if (! array_key_exists((string) $key, self::STRING_PARAMETER_LIMITS) && ! in_array((string) $key, ['limit', 'offset'], true)) {
                    $unsupported++;

                    continue;
                }

                if (! is_scalar($value) || (string) $value === '') {
                    $invalid++;

                    continue;
                }

                $pairs[(string) $key] = (string) $value;
            }
        }

        $parameters = [];

        foreach ($pairs as $key => $value) {
            if ($this->containsVariable($value)) {
                return [
                    'parameters' => [],
                    'invalid' => $invalid,
                    'unsupported' => $unsupported,
                    'contains_variable' => true,
                ];
            }

            if (isset(self::STRING_PARAMETER_LIMITS[$key])) {
                if (mb_strlen($value) > self::STRING_PARAMETER_LIMITS[$key]) {
                    $invalid++;

                    continue;
                }

                $parameters[$key] = $value;

                continue;
            }

            if (! ctype_digit($value)) {
                $invalid++;

                continue;
            }

            $integer = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 2_147_483_647],
            ]);

            if ($integer === false || ($key === 'limit' && ($integer < 1 || $integer > 500))) {
                $invalid++;

                continue;
            }

            $parameters[$key] = $integer;
        }

        /** @var ImportParameters $parameters */
        return [
            'parameters' => $parameters,
            'invalid' => $invalid,
            'unsupported' => $unsupported,
            'contains_variable' => false,
        ];
    }

    private function semanticResourceKey(string $path): ?string
    {
        if (preg_match('#^/(hcm|fscm)RestApi/resources/(?:latest|\d+(?:\.\d+){3})/([^/]+)#', $path, $matches) !== 1) {
            return null;
        }

        return $this->semanticResources[mb_strtolower($matches[1].':'.$matches[2])] ?? null;
    }

    private function isTenantSpecificPath(string $path): bool
    {
        return preg_match('#^/(?:hcm|fscm)RestApi/resources/(?:latest|\d+(?:\.\d+){3})/[^/]+/.+#', $path) === 1;
    }

    private function hasSourceHost(mixed $url): bool
    {
        $raw = is_array($url) ? (string) ($url['raw'] ?? '') : (string) $url;

        return preg_match('#^(?:https?://|\{\{[^}]+\}\}/)#i', trim($raw)) === 1;
    }

    private function containsVariable(string $value): bool
    {
        return preg_match('/\{\{[^{}]+\}\}/', $value) === 1;
    }

    /**
     * @param  ImportParameters  $parameters
     */
    private function fingerprint(string $path, array $parameters): string
    {
        ksort($parameters);

        return hash('sha256', json_encode(
            [$path, $parameters],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function cleanName(mixed $value, string $fallback): string
    {
        $name = is_scalar($value) ? (string) $value : '';
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
        $name = Str::squish($name);

        return Str::limit($name !== '' ? $name : $fallback, 255, '');
    }

    /**
     * @return array{writes: int, describe: int, other: int, variables: int, duplicates: int, invalid_path: int, too_long: int, invalid_parameters: int}
     */
    private function emptySkippedCounts(): array
    {
        return [
            'writes' => 0,
            'describe' => 0,
            'other' => 0,
            'variables' => 0,
            'duplicates' => 0,
            'invalid_path' => 0,
            'too_long' => 0,
            'invalid_parameters' => 0,
        ];
    }
}
