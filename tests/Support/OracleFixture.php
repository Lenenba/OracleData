<?php

namespace Tests\Support;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class OracleFixture
{
    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function load(string $relativePath): array
    {
        if (
            $relativePath === ''
            || str_contains($relativePath, '..')
            || preg_match('/^(?:[a-zA-Z]:|[\\\\\\/])/', $relativePath) === 1
        ) {
            throw new InvalidArgumentException('Oracle fixture paths must be relative and remain inside the fixture root.');
        }

        $path = dirname(__DIR__)
            .DIRECTORY_SEPARATOR
            .'Fixtures'
            .DIRECTORY_SEPARATOR
            .'oracle'
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read Oracle fixture [{$relativePath}].");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Oracle fixture [{$relativePath}] must decode to an object.");
        }

        return $decoded;
    }
}
