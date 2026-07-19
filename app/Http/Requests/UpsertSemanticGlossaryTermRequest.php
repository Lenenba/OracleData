<?php

namespace App\Http\Requests;

use App\Enums\SemanticClassification;
use App\Enums\SemanticDataCategory;
use App\Models\SemanticGlossaryTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertSemanticGlossaryTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        $term = $this->route('semanticGlossaryTerm');

        return $term instanceof SemanticGlossaryTerm
            ? $this->user()?->can('update', $term) === true
            : $this->user()?->can('create', SemanticGlossaryTerm::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => [Rule::requiredIf($this->route('semanticGlossaryTerm') !== null), 'integer', 'min:1'],
            'term_key' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_.-]*$/', Rule::unique('semantic_glossary_terms', 'term_key')->ignore($this->route('semanticGlossaryTerm'))],
            'domain' => ['nullable', 'string', 'max:100'],
            'classification' => ['required', Rule::enum(SemanticClassification::class)],
            'data_category' => ['required', Rule::enum(SemanticDataCategory::class)],
            'is_active' => ['required', 'boolean'],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.en' => ['required', 'array'],
            'translations.es' => ['required', 'array'],
            'translations.*.term' => ['required', 'string', 'max:255'],
            'translations.*.definition' => ['required', 'string', 'max:5000'],
            'translations.*.synonyms' => ['present', 'array', 'max:50'],
            'translations.fr.synonyms.*' => ['string', 'max:120', 'distinct'],
            'translations.en.synonyms.*' => ['string', 'max:120', 'distinct'],
            'translations.es.synonyms.*' => ['string', 'max:120', 'distinct'],
            'translations.*.forbidden_terms' => ['present', 'array', 'max:50'],
            'translations.fr.forbidden_terms.*' => ['string', 'max:120', 'distinct'],
            'translations.en.forbidden_terms.*' => ['string', 'max:120', 'distinct'],
            'translations.es.forbidden_terms.*' => ['string', 'max:120', 'distinct'],
            'translations.*.examples' => ['present', 'array', 'max:20'],
            'translations.*.examples.*' => ['string', 'max:500'],
        ];
    }
}
