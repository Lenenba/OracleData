<?php

namespace App\Concerns;

use App\Rules\AllowedResourcePath;

/**
 * Règles de validation partagées entre la création et la mise à jour d'une requête.
 */
trait QueryValidationRules
{
    /**
     * Get the validation rules shared by query form requests.
     *
     * @return array<string, mixed>
     */
    protected function queryRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'resource_path' => ['required', 'string', new AllowedResourcePath],
            'parameters' => ['nullable', 'array'],
            'parameters.limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'parameters.q' => ['nullable', 'string', 'max:1000'],
            'parameters.fields' => ['nullable', 'string', 'max:1000'],
            'parameters.offset' => ['nullable', 'integer', 'min:0'],
            'visibility' => ['required', 'in:private,shared'],
        ];
    }
}
