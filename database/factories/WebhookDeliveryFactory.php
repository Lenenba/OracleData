<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'user_id' => User::factory(),
            'event_type' => WebhookEvent::AlertTriggered->value,
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'status_code' => 200,
            'attempts' => 1,
            'error' => null,
            'delivered_at' => now(),
        ];
    }
}
