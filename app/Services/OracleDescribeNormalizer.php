<?php

namespace App\Services;

use Illuminate\Support\Str;
use UnexpectedValueException;

class OracleDescribeNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{title: string|null, fields: list<string>, attributes: list<array<string, mixed>>, schema_hash: string}
     */
    public function normalize(array $payload, string $resourceKey): array
    {
        $resource = $this->resourceNode($payload, $resourceKey);

        if ($resource === null) {
            throw new UnexpectedValueException('La réponse Oracle describe ne contient aucune ressource exploitable.');
        }

        $rawAttributes = $this->arrayValue($resource, ['attributes', 'Attributes']);
        $attributes = [];

        foreach ($rawAttributes as $rawAttribute) {
            if (! is_array($rawAttribute)) {
                continue;
            }

            $name = trim((string) $this->value($rawAttribute, ['name', 'Name'], ''));

            if ($name === '') {
                continue;
            }

            $attribute = ['name' => $name];

            foreach ($rawAttribute as $key => $value) {
                $normalizedKey = Str::snake((string) $key);

                if (in_array($normalizedKey, ['name', 'links', 'link', 'href', 'url'], true) || $value === null) {
                    continue;
                }

                $attribute[$normalizedKey] = $this->canonicalize($value);
            }

            ksort($attribute);
            $attributes[$name] = $attribute;
        }

        if ($attributes === []) {
            throw new UnexpectedValueException('La réponse Oracle describe ne contient aucun attribut nommé.');
        }

        uksort($attributes, 'strnatcasecmp');
        $attributes = array_values($attributes);
        $titleValue = $this->value($resource, ['title', 'Title'], null);
        $title = is_scalar($titleValue) && trim((string) $titleValue) !== ''
            ? trim((string) $titleValue)
            : null;
        $hashPayload = ['title' => $title, 'attributes' => $attributes];

        return [
            'title' => $title,
            'fields' => array_column($attributes, 'name'),
            'attributes' => $attributes,
            'schema_hash' => hash('sha256', json_encode(
                $hashPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function resourceNode(array $payload, string $resourceKey): ?array
    {
        foreach (['Resources', 'resources'] as $containerKey) {
            $container = $payload[$containerKey] ?? null;

            if (! is_array($container)) {
                continue;
            }

            $direct = $container[$resourceKey] ?? null;

            if (is_array($direct) && $this->hasAttributes($direct)) {
                return $direct;
            }

            foreach ($container as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $name = (string) $this->value($candidate, ['name', 'Name'], '');

                if ($name === $resourceKey && $this->hasAttributes($candidate)) {
                    return $candidate;
                }
            }

            $candidates = array_values(array_filter(
                $container,
                fn (mixed $candidate): bool => is_array($candidate) && $this->hasAttributes($candidate),
            ));

            if (count($candidates) === 1) {
                return $candidates[0];
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $value */
    private function hasAttributes(array $value): bool
    {
        return is_array($value['attributes'] ?? $value['Attributes'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $keys
     */
    private function value(array $values, array $keys, mixed $default): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        }

        return $default;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private function arrayValue(array $values, array $keys): array
    {
        $value = $this->value($values, $keys, []);

        return is_array($value) ? $value : [];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $canonical = [];

        foreach ($value as $key => $nested) {
            $canonical[Str::snake((string) $key)] = $this->canonicalize($nested);
        }

        ksort($canonical);

        return $canonical;
    }
}
