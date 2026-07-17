<?php

namespace App\Http\Requests;

use App\Rules\SafeOracleBaseUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreOracleTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalize the tenant key and base URL before validation.
     */
    protected function prepareForValidation(): void
    {
        $key = trim((string) $this->input('key'));

        if ($key === '' && $this->filled('label')) {
            $key = (string) $this->input('label');
        }

        if ($key !== '') {
            $key = (string) Str::of($key)
                ->ascii()
                ->lower()
                ->replaceMatches('/[^a-z0-9_-]+/', '_')
                ->trim('_');
        }

        $baseUrl = rtrim(trim((string) $this->input('base_url')), '/');

        $this->merge([
            'key' => $key,
            'base_url' => $baseUrl,
            'is_default' => $this->boolean('is_default'),
            'is_active' => true,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('oracle_tenants', 'key')
                    ->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
            ],
            'label' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:2048', new SafeOracleBaseUrl],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
            'auth_type' => ['sometimes', Rule::in(['basic'])],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
