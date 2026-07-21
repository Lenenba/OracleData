<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises the parameter definitions that an owner attaches to
 * a personal query (lot 10A). The shape mirrors the QueryTemplate definitions
 * so that the shared front-end components work without modification.
 */
class QueryParameterBinder
{
    /** @var list<string> */
    private const array ALLOWED_TYPES = ['string', 'integer', 'number', 'date', 'boolean', 'select'];

    /** @var list<string> */
    private const array ALLOWED_BINDING_KINDS = ['filter', 'parameter'];

    /** @var list<string> */
    private const array ALLOWED_FILTER_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE'];

    /** @var list<string> */
    private const array ALLOWED_PARAMETER_TARGETS = ['limit', 'offset'];

    private const int MAX_DEFINITIONS = 20;

    /**
     * Validate a raw list of parameter definitions supplied by the query owner.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function validate(array $definitions): array
    {
        if (count($definitions) > self::MAX_DEFINITIONS) {
            throw ValidationException::withMessages([
                'parameter_definitions' => __('Une requete ne peut pas avoir plus de :max parametres.', ['max' => self::MAX_DEFINITIONS]),
            ]);
        }

        $keys = [];
        $normalised = [];

        foreach ($definitions as $index => $raw) {
            if (! is_array($raw)) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}" => __('La definition du parametre est invalide.'),
                ]);
            }

            $key = (string) ($raw['key'] ?? '');

            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.key" => __('Les cles de parametres doivent etre uniques et sures.'),
                ]);
            }

            if (in_array($key, $keys, true)) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.key" => __('Les cles de parametres doivent etre uniques et sures.'),
                ]);
            }

            $type = (string) ($raw['type'] ?? '');

            if (! in_array($type, self::ALLOWED_TYPES, true)) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.type" => __("Le type du parametre n'est pas pris en charge."),
                ]);
            }

            $label = trim((string) ($raw['label'] ?? ''));

            if ($label === '' || mb_strlen($label) > 255) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.label" => __('Le libelle du parametre est obligatoire (255 caracteres max).'),
                ]);
            }

            $binding = $raw['binding'] ?? null;

            if (is_array($binding)) {
                $this->validateBinding($binding, $type, $index);
            }

            $keys[] = $key;
            $normalised[] = $this->normaliseDefinition($raw, $key, $type, $label);
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function validateBinding(array $binding, string $type, int $index): void
    {
        $kind = (string) ($binding['kind'] ?? '');

        if (! in_array($kind, self::ALLOWED_BINDING_KINDS, true)) {
            throw ValidationException::withMessages([
                "parameter_definitions.{$index}.binding.kind" => __("Le type de liaison du parametre n'est pas autorise."),
            ]);
        }

        if ($kind === 'filter') {
            $field = (string) ($binding['field'] ?? '');
            $operator = strtoupper(trim((string) ($binding['operator'] ?? '=')));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.binding.field" => __("Le champ de filtre n'appartient pas a la ressource Oracle."),
                ]);
            }

            if (! in_array($operator, self::ALLOWED_FILTER_OPERATORS, true)) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.binding.operator" => __("L'operateur de filtre n'est pas autorise."),
                ]);
            }
        } elseif ($kind === 'parameter') {
            $target = (string) ($binding['key'] ?? '');

            if (! in_array($target, self::ALLOWED_PARAMETER_TARGETS, true)) {
                throw ValidationException::withMessages([
                    "parameter_definitions.{$index}.binding.key" => __("Le parametre d'execution cible n'est pas autorise."),
                ]);
            }
        }
    }

    /**
     * Produce a canonical definition array from validated raw input.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normaliseDefinition(array $raw, string $key, string $type, string $label): array
    {
        $definition = [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'required' => (bool) ($raw['required'] ?? false),
        ];

        if (isset($raw['description']) && (string) $raw['description'] !== '') {
            $definition['description'] = mb_substr(trim((string) $raw['description']), 0, 500);
        }

        if (array_key_exists('default', $raw) && $raw['default'] !== null && $raw['default'] !== '') {
            $definition['default'] = $raw['default'];
        }

        foreach (['min', 'max', 'step'] as $numeric) {
            if (isset($raw[$numeric]) && is_numeric($raw[$numeric])) {
                $definition[$numeric] = $raw[$numeric];
            }
        }

        if ($type === 'select' && is_array($raw['options'] ?? null)) {
            $options = array_values(array_filter(
                array_map(fn (mixed $opt): ?array => is_array($opt)
                    && isset($opt['value'], $opt['label'])
                    && (string) $opt['value'] !== ''
                    ? ['value' => (string) $opt['value'], 'label' => (string) $opt['label']]
                    : null,
                    $raw['options'],
                ),
            ));

            if (count($options) === 0) {
                throw ValidationException::withMessages([
                    'parameter_definitions' => __('Les options de liste doivent etre non vides et uniques.'),
                ]);
            }

            $definition['options'] = $options;
        }

        if (is_array($raw['binding'] ?? null)) {
            $binding = $raw['binding'];
            $kind = (string) ($binding['kind'] ?? '');
            $normalised = ['kind' => $kind];

            if ($kind === 'filter') {
                $normalised['field'] = (string) ($binding['field'] ?? '');
                $normalised['operator'] = strtoupper(trim((string) ($binding['operator'] ?? '=')));
            } elseif ($kind === 'parameter') {
                $normalised['key'] = (string) ($binding['key'] ?? '');
            }

            $definition['binding'] = $normalised;
        }

        return $definition;
    }
}
