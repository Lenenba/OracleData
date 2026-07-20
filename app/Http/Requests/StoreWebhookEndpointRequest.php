<?php

namespace App\Http\Requests;

use App\Enums\WebhookEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookEndpointRequest extends FormRequest
{
    /**
     * Ownership of the endpoint is enforced in the controller.
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
            'url' => ['required', 'url:https', 'max:2048'],
            // A secret is mandatory on creation and optional (kept) on update.
            'secret' => [
                Rule::requiredIf(fn (): bool => $this->route('webhookEndpoint') === null),
                'nullable',
                'string',
                'min:16',
                'max:255',
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvent::values())],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
