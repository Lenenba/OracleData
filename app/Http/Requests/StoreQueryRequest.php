<?php

namespace App\Http\Requests;

use App\Concerns\QueryValidationRules;
use App\Services\FusionManager;
use App\Services\OracleResourceCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreQueryRequest extends FormRequest
{
    use QueryValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Allow the simplified UI to submit a natural-language intent instead of
     * making the user type the Oracle REST path manually.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('tenant_key')) {
            $this->merge([
                'tenant_key' => app(FusionManager::class)->defaultKey(),
            ]);
        }

        if ($this->filled('resource_path')) {
            return;
        }

        $intent = trim((string) $this->input('intent', ''));

        if ($intent === '') {
            return;
        }

        $limit = data_get($this->input('parameters', []), 'limit', 25);
        $catalog = app(OracleResourceCatalog::class);
        $draft = $catalog->draft($intent, $limit);

        $this->merge([
            'name' => $draft['name'] ?? Str::limit($intent, 80, ''),
            'description' => $intent,
            'resource_path' => $draft['resource_path'] ?? '',
            'parameters' => $draft['parameters'] ?? ['limit' => $catalog->clampLimit($limit)],
            'visibility' => $this->input('visibility', 'private'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->queryRules();
    }
}
