<?php

use App\Enums\QueryAccessLevel;
use App\Enums\QuerySharePermission;
use App\Models\Query;
use App\Models\QueryUserShare;
use App\Models\User;
use App\Policies\QueryPolicy;
use Illuminate\Support\Facades\DB;

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

test('the factory builds all query access levels', function () {
    expect(Query::factory()->private()->create()->access_level)->toBe(QueryAccessLevel::PRIVATE)
        ->and(Query::factory()->restricted()->create()->access_level)->toBe(QueryAccessLevel::RESTRICTED)
        ->and(Query::factory()->organization()->create()->access_level)->toBe(QueryAccessLevel::ORGANIZATION)
        ->and(Query::factory()->shared()->create()->access_level)->toBe(QueryAccessLevel::ORGANIZATION);
});

test('query share permissions have an explicit cumulative hierarchy', function () {
    expect(QuerySharePermission::VIEW->implies(QuerySharePermission::VIEW))->toBeTrue()
        ->and(QuerySharePermission::VIEW->implies(QuerySharePermission::EXECUTE))->toBeFalse()
        ->and(QuerySharePermission::EXECUTE->implies(QuerySharePermission::VIEW))->toBeTrue()
        ->and(QuerySharePermission::CLONE->implies(QuerySharePermission::EXECUTE))->toBeTrue()
        ->and(QuerySharePermission::MANAGE->implies(QuerySharePermission::CLONE))->toBeTrue()
        ->and(QuerySharePermission::valuesGranting(QuerySharePermission::EXECUTE))
        ->toBe(['execute', 'clone', 'manage']);
});

test('access scopes include owners organization queries and only active restricted shares', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $own = Query::factory()->for($recipient)->private()->create();
    $organization = Query::factory()->for($owner)->organization()->create();
    $active = Query::factory()->for($owner)->restricted()->create();
    $expired = Query::factory()->for($owner)->restricted()->create();
    $revoked = Query::factory()->for($owner)->restricted()->create();
    $private = Query::factory()->for($owner)->private()->create();

    QueryUserShare::factory()->create([
        'query_id' => $active->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $recipient->id,
        'permission' => QuerySharePermission::EXECUTE,
    ]);
    QueryUserShare::factory()->expired()->create([
        'query_id' => $expired->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $recipient->id,
    ]);
    QueryUserShare::factory()->revoked()->create([
        'query_id' => $revoked->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $recipient->id,
    ]);
    QueryUserShare::factory()->create([
        'query_id' => $private->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $recipient->id,
        'permission' => QuerySharePermission::MANAGE,
    ]);

    expect(Query::query()->accessibleTo($recipient)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$own->id, $organization->id, $active->id])->sort()->values()->all())
        ->and(Query::query()->sharedWith($recipient)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$organization->id, $active->id])->sort()->values()->all());
});

test('permission helpers reuse eager loaded shares and keep query updates owner only', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $query = Query::factory()->for($owner)->restricted()->create();
    QueryUserShare::factory()->create([
        'query_id' => $query->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $recipient->id,
        'permission' => QuerySharePermission::MANAGE,
    ]);
    $query->load('userShares');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $permission = $query->permissionFor($recipient);
    $canView = $query->allows($recipient, QuerySharePermission::VIEW);
    $canManage = $query->allows($recipient, QuerySharePermission::MANAGE);
    $databaseQueries = DB::getQueryLog();

    DB::disableQueryLog();

    $policy = new QueryPolicy;

    expect($permission)->toBe(QuerySharePermission::MANAGE)
        ->and($canView)->toBeTrue()
        ->and($canManage)->toBeTrue()
        ->and($databaseQueries)->toBe([])
        ->and($policy->view($recipient, $query))->toBeTrue()
        ->and($policy->execute($recipient, $query))->toBeTrue()
        ->and($policy->clone($recipient, $query))->toBeTrue()
        ->and($policy->manageSharing($recipient, $query))->toBeTrue()
        ->and($policy->changeAccessLevel($recipient, $query))->toBeFalse()
        ->and($policy->changeAccessLevel($owner, $query))->toBeTrue()
        ->and($policy->update($recipient, $query))->toBeFalse()
        ->and($policy->update($owner, $query))->toBeTrue();
});

test('organization access stops at clone and private access ignores residual grants', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $organization = Query::factory()->for($owner)->organization()->create();
    $private = Query::factory()->for($owner)->private()->create();

    QueryUserShare::factory()->create([
        'query_id' => $private->id,
        'shared_by_user_id' => $owner->id,
        'user_id' => $recipient->id,
        'permission' => QuerySharePermission::MANAGE,
    ]);

    expect($organization->allows($recipient, QuerySharePermission::VIEW))->toBeTrue()
        ->and($organization->allows($recipient, QuerySharePermission::EXECUTE))->toBeTrue()
        ->and($organization->allows($recipient, QuerySharePermission::CLONE))->toBeTrue()
        ->and($organization->allows($recipient, QuerySharePermission::MANAGE))->toBeFalse()
        ->and($private->allows($recipient, QuerySharePermission::VIEW))->toBeFalse()
        ->and($private->allows($recipient, QuerySharePermission::MANAGE))->toBeFalse();
});
