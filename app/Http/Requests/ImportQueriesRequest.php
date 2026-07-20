<?php

namespace App\Http\Requests;

use App\Services\FusionManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportQueriesRequest extends FormRequest
{
    private const int MAX_COLLECTION_BYTES = 5 * 1024 * 1024;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantKeys = $this->user() === null
            ? []
            : app(FusionManager::class)->forUser($this->user())->keys();

        return [
            'collection' => [
                'required',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $encoded = json_encode($value);

                    if (! is_string($encoded) || strlen($encoded) > self::MAX_COLLECTION_BYTES) {
                        $fail(__('La collection Postman ne doit pas dépasser 5 Mio.'));
                    }
                },
            ],
            'collection.info' => ['nullable', 'array'],
            'collection.info.name' => ['nullable', 'string', 'max:255'],
            'collection.info.schema' => [
                'nullable',
                'string',
                'max:500',
                'regex:#^https?://schema\.getpostman\.com/json/collection/v2\.(?:0|1)\.0/collection\.json$#i',
            ],
            'collection.item' => ['required', 'array'],
            'selected' => ['nullable', 'array', 'max:200'],
            'selected.*' => ['integer', 'min:0', 'max:499', 'distinct'],
            'tenant_key' => [
                $this->routeIs('queries.import') ? 'required' : 'nullable',
                'string',
                Rule::in($tenantKeys),
            ],
        ];
    }
}
