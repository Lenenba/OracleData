<?php

use App\Models\Query;
use App\Models\User;

test('parameters are cast to an array', function () {
    $query = Query::factory()->create([
        'parameters' => ['limit' => 25, 'q' => 'DisplayName LIKE "A%"'],
    ]);

    expect($query->refresh()->parameters)->toBe([
        'limit' => 25,
        'q' => 'DisplayName LIKE "A%"',
    ]);
});

test('a query belongs to a user', function () {
    $user = User::factory()->create();
    $query = Query::factory()->for($user)->create();

    expect($query->user)->toBeInstanceOf(User::class)
        ->and($query->user->id)->toBe($user->id);
});

test('a user has many queries', function () {
    $user = User::factory()->create();
    Query::factory()->count(2)->for($user)->create();

    expect($user->queries)->toHaveCount(2);
});

test('the factory builds private and shared queries', function () {
    expect(Query::factory()->private()->create()->visibility)->toBe('private')
        ->and(Query::factory()->shared()->create()->visibility)->toBe('shared');
});
