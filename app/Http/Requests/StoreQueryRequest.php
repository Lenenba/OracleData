<?php

namespace App\Http\Requests;

use App\Concerns\QueryValidationRules;
use App\Services\FusionManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
     * The form submits the query already resolved by the preview step; here we
     * only fill safe defaults for fields the UI may leave implicit.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'mode' => $this->input('mode', 'single'),
            'access_level' => $this->input('access_level', 'private'),
            'tenant_key' => $this->filled('tenant_key')
                ? $this->input('tenant_key')
                : app(FusionManager::class)->defaultKey(),
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
