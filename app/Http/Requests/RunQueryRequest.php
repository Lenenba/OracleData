<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RunQueryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * View authorization is handled in the controller via the QueryPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant' => ['required', 'string', Rule::in(array_keys(config('fusion.tenants', [])))],
        ];
    }

    /**
     * Always return validation errors as JSON: the run endpoint is consumed by
     * an XHR call, and the app only auto-renders JSON exceptions for `api/*`.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => __('Les données fournies sont invalides.'),
            'errors' => $validator->errors(),
        ], 422));
    }
}
