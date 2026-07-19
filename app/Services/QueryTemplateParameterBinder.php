<?php

namespace App\Services;

use App\Models\QueryTemplate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validate user-supplied template values and bind them to an allow-listed
 * Oracle query definition. The browser never builds or interpolates `q`.
 */
class QueryTemplateParameterBinder
{
    /** @var list<string> */
    private const array FILTER_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE'];

    /** @var list<string> */
    private const array RUNTIME_PARAMETER_KEYS = ['limit', 'offset'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{parameters: array<string, mixed>, values: array<string, mixed>}
     *
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function bind(QueryTemplate $template, array $input): array
    {
        $definitions = $template->parameterDefinitionsFor(app()->getLocale());
        $definitionKeys = array_map(
            fn (array $definition): string => (string) ($definition['key'] ?? ''),
            $definitions,
        );

        foreach (array_keys($input) as $key) {
            if (! in_array((string) $key, $definitionKeys, true)) {
                throw ValidationException::withMessages([
                    "parameter_values.{$key}" => __('Ce paramètre n’est pas autorisé pour ce modèle.'),
                ]);
            }
        }

        $valuesWithDefaults = $input;
        $rules = ['parameter_values' => ['array']];
        $attributes = [];

        foreach ($definitions as $definition) {
            $key = $this->definitionKey($definition);

            if (! array_key_exists($key, $valuesWithDefaults) && array_key_exists('default', $definition)) {
                $valuesWithDefaults[$key] = $definition['default'];
            }

            $rules["parameter_values.{$key}"] = $this->rulesFor($definition);
            $attributes["parameter_values.{$key}"] = (string) ($definition['label'] ?? $key);
        }

        /** @var array{parameter_values?: array<string, mixed>} $validated */
        $validated = Validator::make(
            ['parameter_values' => $valuesWithDefaults],
            $rules,
            [],
            $attributes,
        )->validate();

        $parameters = $template->parameters ?? [];
        $resolvedValues = [];
        $filterClauses = [];

        foreach ($definitions as $definition) {
            $key = $this->definitionKey($definition);

            if (! array_key_exists($key, $validated['parameter_values'] ?? [])) {
                continue;
            }

            $rawValue = $validated['parameter_values'][$key];

            if (($rawValue === null || $rawValue === '') && ! (bool) ($definition['required'] ?? false)) {
                continue;
            }

            $value = $this->castValue(
                $rawValue,
                (string) ($definition['type'] ?? 'string'),
            );
            $resolvedValues[$key] = $value;
            $binding = $definition['binding'] ?? null;

            if (! is_array($binding)) {
                throw new InvalidArgumentException("Le paramètre [{$key}] ne possède pas de liaison valide.");
            }

            $kind = (string) ($binding['kind'] ?? 'filter');

            if ($kind === 'filter') {
                $filterClauses[] = $this->filterClause($binding, $value, $definition);

                continue;
            }

            if ($kind === 'parameter') {
                $target = (string) ($binding['key'] ?? '');

                if (! in_array($target, self::RUNTIME_PARAMETER_KEYS, true)) {
                    throw new InvalidArgumentException("Le paramètre Oracle [{$target}] n’est pas personnalisable.");
                }

                $parameters[$target] = $value;

                continue;
            }

            throw new InvalidArgumentException("Le type de liaison [{$kind}] n’est pas pris en charge.");
        }

        $baseFilter = trim((string) ($parameters['q'] ?? ''));
        $filters = array_values(array_filter([$baseFilter, ...$filterClauses]));

        if ($filters !== []) {
            $parameters['q'] = implode(' AND ', $filters);
        } else {
            unset($parameters['q']);
        }

        if (mb_strlen((string) ($parameters['q'] ?? '')) > 2000) {
            throw new InvalidArgumentException('Le filtre produit par ce modèle est trop long.');
        }

        if (($parameters['resource_key'] ?? null) !== $template->resource_key) {
            throw new InvalidArgumentException('La ressource du modèle de requête est incohérente.');
        }

        return [
            'parameters' => $parameters,
            'values' => $resolvedValues,
        ];
    }

    /**
     * Convert persisted canonical parameters into an OracleQueryTool input.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function toToolQuery(array $parameters): array
    {
        $resourceKey = trim((string) ($parameters['resource_key'] ?? ''));

        if ($resourceKey === '') {
            throw new InvalidArgumentException('Le modèle ne définit aucune ressource Oracle.');
        }

        $query = [
            'resource' => $resourceKey,
            'fields' => $parameters['fields'] ?? [],
            'expand' => $parameters['expand'] ?? [],
            'joins' => $parameters['joins'] ?? [],
            'child_fields' => $parameters['child_fields'] ?? [],
            'limit' => $parameters['limit'] ?? 25,
        ];

        foreach (['q', 'orderBy', 'offset'] as $key) {
            if (isset($parameters[$key]) && $parameters[$key] !== '') {
                $query[$key] = $parameters[$key];
            }
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, mixed>
     */
    private function rulesFor(array $definition): array
    {
        $rules = [(bool) ($definition['required'] ?? false) ? 'required' : 'nullable'];
        $type = (string) ($definition['type'] ?? 'string');

        if ($type === 'number') {
            $rules[] = 'numeric';
        } elseif ($type === 'integer') {
            $rules[] = 'integer';
        } elseif ($type === 'date') {
            $rules[] = 'date_format:Y-m-d';
        } elseif ($type === 'select') {
            $rules[] = 'string';
            $rules[] = Rule::in($this->optionValues($definition));
        } elseif ($type === 'boolean') {
            $rules[] = 'boolean';
        } elseif ($type === 'string') {
            $rules[] = 'string';
        } else {
            throw new InvalidArgumentException("Le type de paramètre [{$type}] n’est pas pris en charge.");
        }

        if (isset($definition['min']) && is_numeric($definition['min'])) {
            $rules[] = 'min:'.$definition['min'];
        }

        if (isset($definition['max']) && is_numeric($definition['max'])) {
            $rules[] = 'max:'.$definition['max'];
        } elseif (in_array($type, ['string', 'select'], true)) {
            $rules[] = 'max:255';
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function optionValues(array $definition): array
    {
        $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];

        return array_values(array_map(
            fn (mixed $option): string => is_array($option)
                ? (string) ($option['value'] ?? '')
                : (string) $option,
            $options,
        ));
    }

    /**
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $definition
     */
    private function filterClause(array $binding, mixed $value, array $definition): string
    {
        $field = (string) ($binding['field'] ?? '');
        $operator = strtoupper(trim((string) ($binding['operator'] ?? '=')));

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            throw new InvalidArgumentException("Le champ de filtre [{$field}] est invalide.");
        }

        if (! in_array($operator, self::FILTER_OPERATORS, true)) {
            throw new InvalidArgumentException("L’opérateur de filtre [{$operator}] est invalide.");
        }

        return $field.' '.$operator.' '.$this->formatFilterValue(
            $value,
            (string) ($definition['type'] ?? 'string'),
        );
    }

    private function formatFilterValue(mixed $value, string $type): string
    {
        if ($type === 'integer') {
            return (string) (int) $value;
        }

        if ($type === 'number') {
            $number = (float) $value;

            if (! is_finite($number)) {
                throw new InvalidArgumentException('La valeur numérique du filtre est invalide.');
            }

            $formatted = rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');

            return $formatted === '-0' ? '0' : $formatted;
        }

        if ($type === 'boolean') {
            return $value ? 'true' : 'false';
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number' => (float) $value,
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => (string) $value,
        };
    }

    /** @param array<string, mixed> $definition */
    private function definitionKey(array $definition): string
    {
        $key = (string) ($definition['key'] ?? '');

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
            throw new InvalidArgumentException("La clé de paramètre [{$key}] est invalide.");
        }

        return $key;
    }
}
