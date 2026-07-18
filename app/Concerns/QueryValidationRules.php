<?php

namespace App\Concerns;

use App\Rules\AllowedResourcePath;
use App\Services\FusionManager;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'description' => ['nullable', 'string', 'max:2000'],
            'mode' => ['required', Rule::in(['single', 'agent'])],
            'resource_path' => ['nullable', 'required_if:mode,single', 'string', new AllowedResourcePath],
            'tenant_key' => ['required', 'string', Rule::in(app(FusionManager::class)->keys())],
            'parameters' => ['nullable', 'array'],
            'parameters.limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'parameters.q' => ['nullable', 'string', 'max:2000'],
            'parameters.fields' => ['nullable', 'string', 'max:2000'],
            'parameters.expand' => ['nullable', 'string', 'max:500'],
            'parameters.joins' => ['nullable', 'string', 'max:500'],
            'parameters.child_fields' => ['nullable', 'array'],
            'parameters.child_fields.*' => ['array'],
            'parameters.child_fields.*.*' => ['string', 'max:100'],
            'parameters.orderBy' => ['nullable', 'string', 'max:500'],
            'parameters.offset' => ['nullable', 'integer', 'min:0'],
            'parameters.resource_key' => ['nullable', 'string'],
            'visibility' => ['required', 'in:private,shared'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => [
                'string',
                'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && Str::slug(trim($value)) === '') {
                        $fail(__('Un tag doit contenir au moins un caractère pouvant former un identifiant.'));
                    }
                },
            ],
        ];
    }
}
