<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lot 10A — validates the raw parameter definitions payload before it is
 * handed to QueryParameterBinder for deep structural validation.
 *
 * Shallow rules here ensure each element is an array and that string fields
 * are not absurdly long; the binder enforces semantics (key pattern, type
 * whitelist, binding consistency, uniqueness, etc.).
 */
class UpdateQueryParametersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parameter_definitions' => ['present', 'array', 'max:20'],
            'parameter_definitions.*' => ['array'],
            'parameter_definitions.*.key' => ['required', 'string', 'max:64'],
            'parameter_definitions.*.type' => ['required', 'string', 'max:20'],
            'parameter_definitions.*.label' => ['required', 'string', 'max:255'],
            'parameter_definitions.*.description' => ['nullable', 'string', 'max:500'],
            'parameter_definitions.*.required' => ['nullable', 'boolean'],
            'parameter_definitions.*.default' => ['nullable'],
            'parameter_definitions.*.min' => ['nullable', 'numeric'],
            'parameter_definitions.*.max' => ['nullable', 'numeric'],
            'parameter_definitions.*.step' => ['nullable', 'numeric'],
            'parameter_definitions.*.options' => ['nullable', 'array', 'max:100'],
            'parameter_definitions.*.options.*.value' => ['required', 'string', 'max:255'],
            'parameter_definitions.*.options.*.label' => ['required', 'string', 'max:255'],
            'parameter_definitions.*.binding' => ['nullable', 'array'],
            'parameter_definitions.*.binding.kind' => ['required_with:parameter_definitions.*.binding', 'string', 'max:20'],
            'parameter_definitions.*.binding.field' => ['nullable', 'string', 'max:100'],
            'parameter_definitions.*.binding.operator' => ['nullable', 'string', 'max:10'],
            'parameter_definitions.*.binding.key' => ['nullable', 'string', 'max:20'],
        ];
    }
}
