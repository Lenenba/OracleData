<?php

namespace App\Http\Requests;

use App\Enums\DataQualityRunPurpose;
use App\Models\OracleTenant;
use App\Models\QueryTemplate;
use App\Models\QueryTemplateVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQueryTemplateQualityRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('queryTemplate');
        $version = $this->route('queryTemplateVersion');

        return $template instanceof QueryTemplate
            && $version instanceof QueryTemplateVersion
            && $version->belongsToTemplate($template)
            && $this->user()?->can('runQualityValidation', [$template, $version]) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $version = $this->route('queryTemplateVersion');
        $versionId = $version instanceof QueryTemplateVersion ? $version->id : 0;

        return [
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
            'reference_dataset_id' => [
                'nullable',
                'integer',
                Rule::exists('query_template_reference_datasets', 'id')->where(
                    fn ($query) => $query->where(
                        'query_template_version_id',
                        $versionId,
                    ),
                ),
            ],
            'purpose' => ['nullable', Rule::enum(DataQualityRunPurpose::class)],
        ];
    }
}
