<?php

use App\Services\ClaudeClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic', [
        'api_key' => 'sk-test-123',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-opus-4-8',
        'version' => '2023-06-01',
    ]);
});

test('messages() posts to the Anthropic endpoint with auth and version headers', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'hi']],
        'stop_reason' => 'end_turn',
    ])]);

    $response = app(ClaudeClient::class)->messages([
        'max_tokens' => 100,
        'messages' => [['role' => 'user', 'content' => 'hello']],
    ]);

    expect($response['stop_reason'])->toBe('end_turn');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.anthropic.com/v1/messages')
        && $request->hasHeader('x-api-key', 'sk-test-123')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request['model'] === 'claude-opus-4-8');
});

test('messages() defaults the model from config when none is provided', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [], 'stop_reason' => 'end_turn'])]);

    app(ClaudeClient::class)->messages([
        'max_tokens' => 10,
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]);

    Http::assertSent(fn ($request) => $request['model'] === 'claude-opus-4-8');
});

test('a missing API key raises a clean exception', function () {
    config()->set('services.anthropic.api_key', null);

    app(ClaudeClient::class)->messages([
        'max_tokens' => 10,
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]);
})->throws(RuntimeException::class);

test('an Anthropic API error is wrapped in a RuntimeException with a clean message', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

    app(ClaudeClient::class)->messages([
        'max_tokens' => 10,
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ]);
})->throws(RuntimeException::class);
