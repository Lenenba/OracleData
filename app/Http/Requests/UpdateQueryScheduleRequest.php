<?php

namespace App\Http\Requests;

use App\Enums\ScheduleFrequency;
use App\Services\FusionManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQueryScheduleRequest extends FormRequest
{
    /**
     * Ownership of the schedule is enforced in the controller.
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
            'tenant' => ['nullable', 'string', Rule::in(app(FusionManager::class)->keys())],
            'frequency' => ['required', Rule::enum(ScheduleFrequency::class)],
            'time_of_day' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->frequencyRequiresTime()),
                'date_format:H:i',
            ],
            'day_of_week' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('frequency') === ScheduleFrequency::Weekly->value),
                'integer',
                'between:0,6',
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function frequencyRequiresTime(): bool
    {
        return in_array($this->input('frequency'), [
            ScheduleFrequency::Daily->value,
            ScheduleFrequency::Weekly->value,
        ], true);
    }
}
