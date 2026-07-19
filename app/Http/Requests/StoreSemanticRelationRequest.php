<?php

namespace App\Http\Requests;

use App\Enums\SemanticCardinality;
use App\Enums\SemanticRelationKind;
use App\Models\SemanticResource;
use App\Services\OracleResourceCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSemanticRelationRequest extends FormRequest
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
            'target_resource_id' => ['nullable', 'integer', 'exists:semantic_resources,id'],
            'relation_key' => ['required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_.]*$/', Rule::unique('semantic_relations', 'relation_key')],
            'kind' => ['required', Rule::enum(SemanticRelationKind::class)],
            'target_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'source_field' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'target_field' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'cardinality' => ['required', Rule::enum(SemanticCardinality::class)],
            'translations' => ['required', 'array:fr,en,es'],
            'translations.fr' => ['required', 'array'],
            'translations.en' => ['required', 'array'],
            'translations.es' => ['required', 'array'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $resource = $this->route('semanticResource');

            if (! $resource instanceof SemanticResource) {
                return;
            }

            $catalogResource = app(OracleResourceCatalog::class)->find($resource->resource_key);
            $kind = (string) $this->input('kind');
            $targetKey = (string) $this->input('target_key');

            if ($catalogResource === null) {
                $validator->errors()->add('relation_key', __('La ressource source n’existe plus dans le catalogue autorisé.'));

                return;
            }

            if ($kind === SemanticRelationKind::Expand->value) {
                if (! in_array($targetKey, $catalogResource['child_resources'], true)) {
                    $validator->errors()->add('target_key', __('Cet enfant Oracle n’est pas autorisé pour cette ressource.'));
                }

                return;
            }

            if ($kind !== SemanticRelationKind::Join->value) {
                $validator->errors()->add('kind', __('Seules les relations Oracle cataloguées peuvent être ajoutées.'));

                return;
            }

            $join = $catalogResource['join_keys'][$targetKey] ?? null;
            $target = SemanticResource::query()->find($this->integer('target_resource_id'));

            if ($join === null || $target?->resource_key !== $targetKey) {
                $validator->errors()->add('target_resource_id', __('La ressource cible ne correspond pas à une jointure autorisée.'));

                return;
            }

            if ($this->input('source_field') !== $join['local_key']
                || $this->input('target_field') !== $join['remote_key']) {
                $validator->errors()->add('source_field', __('Les clés de jointure doivent correspondre exactement au catalogue Oracle.'));
            }
        }];
    }
}
