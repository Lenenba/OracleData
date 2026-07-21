<?php

namespace App\Services;

use App\Models\Query;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Binds user-supplied runtime values into a personal query's parameters just
 * before it is executed (lot 10A). The logic mirrors QueryTemplateParameterBinder
 * but works from the parameter_definitions column of a personal Query instead
 * of a QueryTemplate.
 */
class RuntimeQueryParameterBinder
{
    /** @var list<string> */
    private const array FILTER_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE'];

    /** @var list<string> */
    private const array RUNTIME_PARAMETER_KEYS = ['limit', 'offset'];

    /**
     * Validate user-supplied runtime values and merge them into the query's
     * stored parameters, honouring the binding rules defined by the owner.
     *
     * @param  array<string, mixed>  $input  User-supplied key-value map.
     * @return array<string, mixed>  Merged Oracle parameter array.
     *
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function bind(Query $query, array $input): array
    {
        /** @var list<array<string, mixed>> $definitions */
        $definitions = $query->parameter_definitions ?? [];

        if ($definitions === []) {
            return $query->parameters ?? [];
        }

        $definitionKeys = array_map(
            fn (array $definition): string => (string) ($definition['key'] ?? ''),
            $definitions,
        );

        // Reject any key that the owner never declared.
        foreach (array_keys($input) as $key) {
            if (! in_array((string) $key, $definitionKeys, true)) {
                throw ValidationException::withMessages([
                    "parameter_values.{$key}" => __("Ce parametre n'est pas autorise pour cette requete."),
                ]);
            }
        }

        // Fill missing values with their declared defaults.
        $valuesWithDefaults = $input;
        $rules = ['parameter_values' => ['array']];
        $attributes = [];

        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');

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

        $parameters = $query->parameters ?? [];
        $filterClauses = [];

        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if (! array_key_exists($key, $validated['parameter_values'] ?? [])) {
                continue;
            }

            $rawValue = $validated['parameter_values'][$key];

            if (($rawValue === null || $rawValue === '') && ! (bool) ($definition['required'] ?? false)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? 'string');
            $value = $this->castValue($rawValue, $type);
            $binding = $definition['binding'] ?? null;

            if (! is_array($binding)) {
                // No binding means the value is accepted but has no effect on
                // the stored parameters — it is a display-only annotation.
                continue;
            }

            $kind = (string) ($binding['kind'] ?? 'filter');

            if ($kind === 'filter') {
                $filterClauses[] = $this->filterClause($binding, $value, $type);

                continue;
            }

            if ($kind === 'parameter') {
                $target = (string) ($binding['key'] ?? '');

                if (! in_array($target, self::RUNTIME_PARAMETER_KEYS, true)) {
                    throw new InvalidArgumentException("Le parametre Oracle [{$target}] n'est pas personnalisable.");
                }

                $parameters[$target] = $value;

                continue;
            }

            throw new InvalidArgumentException("Le type de liaison [{$kind}] n'est pas pris en charge.");
        }

        $baseFilter = trim((string) ($parameters['q'] ?? ''));
        $filters = array_values(array_filter([$baseFilter, ...$filterClauses]));

        if ($filters !== []) {
            $parameters['q'] = implode(' AND ', $filters);
        } else {
            unset($parameters['q']);
        }

        if (mb_strlen((string) ($parameters['q'] ?? '')) > 2000) {
            throw new InvalidArgumentException('Le filtre produit par cette requete est trop long.');
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, mixed>
     */
    private function rulesFor(array $definition): array
    {
        $rules = [(bool) ($definition['required'] ?? false) ? 'required' : 'nullable'];
        $type = (string) ($definition['type'] ?? 'string');

        match ($type) {
            'number' => $rules[] = 'numeric',
            'integer' => $rules[] = 'integer',
            'date' => $rules[] = 'date_format:Y-m-d',
            'select' => array_push($rules, 'string', Rule::in($this->optionValues($definition))),
            'boolean' => $rules[] = 'boolean',
            default => $rules[] = 'string',
        };

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
     */
    private function filterClause(array $binding, mixed $value, string $type): string
    {
        $field = (string) ($binding['field'] ?? '');
        $operator = strtoupper(trim((string) ($binding['operator'] ?? '=')));

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            throw new InvalidArgumentException("Le champ de filtre [{$field}] est invalide.");
        }

        if (! in_array($operator, self::FILTER_OPERATORS, true)) {
            throw new InvalidArgumentException("L'operateur de filtre [{$operator}] est invalide.");
        }

        return $field.' '.$operator.' '.$this->formatFilterValue($value, $type);
    }

    private function formatFilterValue(mixed $value, string $type): string
    {
        if ($type === 'integer') {
            return (string) (int) $value;
        }

        if ($type === 'number') {
            $number = (float) $value;

            if (! is_finite($number)) {
                throw new InvalidArgumentException('La valeur numerique du filtre est invalide.');
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
}
