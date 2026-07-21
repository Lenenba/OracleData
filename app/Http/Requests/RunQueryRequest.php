<?php

namespace App\Http\Requests;

use App\Services\FusionManager;
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
     * Execute authorization is handled in the controller via the QueryPolicy.
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
            'tenant' => ['nullable', 'string', Rule::in(app(FusionManager::class)->keys())],
            // Lot 10A — optional runtime parameter values supplied by the reader.
            // Deep validation (type, binding, allowed keys) is handled by
            // RuntimeQueryParameterBinder once the Query model is known.
            'parameter_values' => ['nullable', 'array', 'max:20'],
            'parameter_values.*' => ['nullable'],
            // Lot 10E — server-side offset pagination. `offset` advances the
            // Oracle cursor; `limit` overrides the per-page default (max 500).
            'offset' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:500'],
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
