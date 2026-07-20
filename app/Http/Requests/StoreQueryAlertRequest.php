<?php

namespace App\Http\Requests;

use App\Enums\AlertCondition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQueryAlertRequest extends FormRequest
{
    /**
     * Ownership of the schedule/alert is enforced in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'condition' => ['required', Rule::enum(AlertCondition::class)],
            'threshold' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->conditionRequiresThreshold()),
                'integer',
                'min:0',
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function conditionRequiresThreshold(): bool
    {
        return in_array($this->input('condition'), [
            AlertCondition::RowCountAbove->value,
            AlertCondition::RowCountBelow->value,
        ], true);
    }
}
