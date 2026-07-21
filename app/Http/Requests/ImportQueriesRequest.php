<?php

namespace App\Http\Requests;

use App\Services\FusionManager;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lot 12A — validates a Postman collection import request.
 *
 * The `collection` field carries the raw parsed Postman JSON (v2.0/v2.1).
 * The `selected` field carries the zero-based indices of candidates the user
 * selected on the preview screen.
 * The `tenant_key` field identifies the target Oracle environment.
 *
 * Deep structural validation of the collection happens inside the action.
 * Here we only check that the required shapes are present so we can give the
 * user a fast, readable error before starting the import.
 */
class ImportQueriesRequest extends FormRequest
{
    private const int MAX_COLLECTION_BYTES = 5 * 1024 * 1024;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantKeys = $this->user() === null
            ? []
            : app(FusionManager::class)->forUser($this->user())->keys();

        return [
            // Raw Postman collection JSON object — must fit within 5 MiB
            // once re-serialised (browsers already enforce the file limit).
            'collection' => [
                'required',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $encoded = json_encode($value);

                    if (! is_string($encoded) || strlen($encoded) > self::MAX_COLLECTION_BYTES) {
                        $fail(__('La collection Postman ne doit pas depasser 5 Mio.'));
                    }
                },
            ],
            'collection.info'        => ['nullable', 'array'],
            'collection.info.name'   => ['nullable', 'string', 'max:255'],
            'collection.info.schema' => [
                'nullable',
                'string',
                'max:500',
            ],
            'collection.item' => ['required', 'array'],

            // Zero-based indices selected by the user on the preview screen.
            // Required only for the store action (preview ignores this field).
            'selected'   => [$this->routeIs('queries.import') ? 'required' : 'nullable', 'array', 'max:200'],
            'selected.*' => ['integer', 'min:0', 'max:499', 'distinct'],

            // Target Oracle environment key — must belong to the current user.
            'tenant_key' => [
                $this->routeIs('queries.import') ? 'required' : 'nullable',
                'string',
                Rule::in($tenantKeys),
            ],
        ];
    }
}
