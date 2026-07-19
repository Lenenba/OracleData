<?php

namespace App\Http\Requests;

use App\Enums\SemanticCardinality;
use App\Enums\SemanticRelationStatus;
use App\Models\SemanticResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSemanticRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource = $this->route('semanticResource');

        return $resource instanceof SemanticResource
            && $this->user()?->can('manageRelations', $resource) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'cardinality' => ['required', Rule::enum(SemanticCardinality::class)],
            'status' => ['required', Rule::enum(SemanticRelationStatus::class)],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.en' => ['required', 'array'],
            'translations.es' => ['required', 'array'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
