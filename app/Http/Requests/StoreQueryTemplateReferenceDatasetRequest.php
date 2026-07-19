<?php

namespace App\Http\Requests;

use App\Enums\ReferenceScenarioType;
use App\Models\OracleTenant;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQueryTemplateReferenceDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('queryTemplate');
        $version = $this->route('queryTemplateVersion');

        return $template instanceof QueryTemplate
            && $version instanceof QueryTemplateVersion
            && $version->belongsToTemplate($template)
            && $this->user()?->can('captureQualityReference', [$template, $version]) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scenario' => ['required', Rule::enum(ReferenceScenarioType::class)],
            'tenant' => [
                'required',
                'string',
                'max:64',
                Rule::exists(OracleTenant::class, 'key')->where(
                    fn ($query) => $query
                        ->where('user_id', $this->user()?->id)
                        ->where('is_active', true),
                ),
            ],
            'parameter_values' => ['nullable', 'array'],
            'comparison_config' => [
                'nullable',
                'array:order_sensitive,duplicate_sensitive,compare_nulls,compare_schema,nested_order_sensitive,aggregates',
            ],
            'comparison_config.order_sensitive' => ['nullable', 'boolean'],
            'comparison_config.duplicate_sensitive' => ['nullable', 'boolean'],
            'comparison_config.compare_nulls' => ['nullable', 'boolean'],
            'comparison_config.compare_schema' => ['nullable', 'boolean'],
            'comparison_config.nested_order_sensitive' => ['nullable', 'boolean'],
            'comparison_config.aggregates' => ['nullable', 'array', 'max:25'],
            'comparison_config.aggregates.*' => ['array:field,function'],
            'comparison_config.aggregates.*.field' => [
                'required',
                'string',
                'regex:/^[A-Za-z_][A-Za-z0-9_-]*(?:\.[A-Za-z_][A-Za-z0-9_-]*)*$/',
            ],
            'comparison_config.aggregates.*.function' => [
                'required',
                Rule::in(['value', 'count', 'distinct_count', 'sum', 'min', 'max', 'avg']),
            ],
        ];
    }
}
