<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Governs a user's own outbound webhook endpoints. The signing secret is
 * write-only: it is stored encrypted and never returned to the client.
 */
class WebhookEndpointController extends Controller
{
    public function store(StoreWebhookEndpointRequest $request): RedirectResponse
    {
        $data = $request->validated();

        WebhookEndpoint::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $data['secret'],
            'events' => array_values(array_unique($data['events'])),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return to_route('automation.index');
    }

    public function update(
        StoreWebhookEndpointRequest $request,
        WebhookEndpoint $webhookEndpoint,
    ): RedirectResponse {
        abort_unless(
            $webhookEndpoint->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $data = $request->validated();
        $attributes = [
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => array_values(array_unique($data['events'])),
            'is_active' => $data['is_active'] ?? true,
        ];

        // Rotate the secret only when a new one is supplied.
        if (! empty($data['secret'])) {
            $attributes['secret'] = $data['secret'];
        }

        $webhookEndpoint->update($attributes);

        return to_route('automation.index');
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        abort_unless(
            $webhookEndpoint->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $webhookEndpoint->delete();

        return to_route('automation.index');
    }
}
