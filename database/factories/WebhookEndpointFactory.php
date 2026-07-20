<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'url' => 'https://hooks.example.com/'.fake()->uuid(),
            'secret' => 'whsec_'.fake()->sha1(),
            'events' => [WebhookEvent::AlertTriggered->value],
            'is_active' => true,
            'last_delivered_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * @param  list<string>  $events
     */
    public function subscribedTo(array $events): static
    {
        return $this->state(fn (): array => ['events' => $events]);
    }
}
