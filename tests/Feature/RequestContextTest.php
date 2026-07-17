<?php

test('responses expose a correlation id and server timing', function () {
    $response = $this->withHeader('X-Request-ID', 'client-request-123')
        ->get('/');

    $response->assertOk()
        ->assertHeader('X-Request-ID', 'client-request-123');

    expect($response->headers->get('Server-Timing'))
        ->toStartWith('app;dur=');
});

test('invalid incoming correlation ids are replaced', function () {
    $response = $this->withHeader('X-Request-ID', 'x')->get('/');

    $response->assertOk();

    expect($response->headers->get('X-Request-ID'))
        ->not->toBe('x')
        ->toHaveLength(36);
});
