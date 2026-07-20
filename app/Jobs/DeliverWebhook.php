<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Delivers one event to a single webhook endpoint, off the request cycle. The
 * body is signed with the endpoint's secret (HMAC-SHA256); only technical
 * metadata is recorded, never the secret or the signature.
 *
 * @phpstan-type WebhookPayload array<string, mixed>
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    /** One delivery row per dispatch; transient failures retry inline below. */
    public int $tries = 1;

    public int $timeout = 60;

    private const int MAX_ATTEMPTS = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public WebhookEndpoint $endpoint,
        public string $eventType,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $endpoint = $this->endpoint->fresh();

        if ($endpoint === null || ! $endpoint->is_active) {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        $status = WebhookDelivery::STATUS_FAILED;
        $statusCode = null;
        $error = null;
        $attempts = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $attempts = $attempt;

            try {
                $response = Http::withHeaders([
                    'X-OracleData-Event' => $this->eventType,
                    'X-OracleData-Signature' => 'sha256='.$signature,
                ])
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withBody($body, 'application/json')
                    ->post($endpoint->url);

                $statusCode = $response->status();

                if ($response->successful()) {
                    $status = WebhookDelivery::STATUS_DELIVERED;
                    $error = null;

                    break;
                }

                $error = 'http_status_'.$statusCode;
            } catch (Throwable) {
                $statusCode = null;
                $error = 'connection_error';
            }
        }

        WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'user_id' => $endpoint->user_id,
            'event_type' => $this->eventType,
            'status' => $status,
            'status_code' => $statusCode,
            'attempts' => $attempts,
            'error' => $error,
            'delivered_at' => $status === WebhookDelivery::STATUS_DELIVERED ? now() : null,
        ]);

        if ($status === WebhookDelivery::STATUS_DELIVERED) {
            $endpoint->forceFill(['last_delivered_at' => now()])->save();
        }
    }
}
