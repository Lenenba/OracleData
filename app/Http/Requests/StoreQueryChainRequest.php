<?php

namespace App\Http\Requests;

use App\Models\QueryChain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation pour la création ou la mise à jour d'une chaîne de requêtes.
 */
class StoreQueryChainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'secondary_query_id' => [
                'required',
                'integer',
                'exists:queries,id',
            ],
            'extraction_field' => ['required', 'string', 'max:100'],
            'injection_param' => ['required', 'string', 'max:100'],
            'injection_operator' => [
                'required',
                Rule::in(QueryChain::ALLOWED_OPERATORS),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0', 'max:255'],
        ];
    }
}
