<?php

namespace App\Http\Requests;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Models\SemanticResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSemanticResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('semanticResource');

        return $resource instanceof SemanticResource
            && $this->user()?->can('update', $resource) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'business_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'technical_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'classification' => ['required', Rule::enum(SemanticClassification::class)],
            'data_category' => ['required', Rule::enum(SemanticDataCategory::class)],
            'is_active' => ['required', 'boolean'],
            'mapping_notes' => ['nullable', 'string', 'max:5000'],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.en' => ['required', 'array'],
            'translations.es' => ['required', 'array'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:5000'],
            'translations.*.synonyms' => ['present', 'array', 'max:50'],
            'translations.fr.synonyms.*' => ['string', 'max:120', 'distinct'],
            'translations.en.synonyms.*' => ['string', 'max:120', 'distinct'],
            'translations.es.synonyms.*' => ['string', 'max:120', 'distinct'],
            'translations.*.examples' => ['present', 'array', 'max:20'],
            'translations.*.examples.*' => ['string', 'max:500'],
        ];
    }
}
