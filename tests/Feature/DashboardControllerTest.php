<?php

use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the dashboard renders the correct Inertia component', function () {
    $this->actingAs(createConnectedUser())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('stats')
            ->has('recentQueries')
            ->has('tenants')
        );
});

test('stats counts only queries accessible to the authenticated user', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();

    // 2 miennes + 1 partagée = 3 accessibles, 1 privée d'un autre = invisible
    Query::factory()->count(2)->for($me)->private()->create();
    Query::factory()->for($other)->shared()->create();
    Query::factory()->for($other)->private()->create();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.totalQueries', 3)
            ->where('stats.myQueries', 2)
        );
});

test('stats.activeTenants reflects configured tenants', function () {
    $this->actingAs(createConnectedUser())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.activeTenants', 1)
        );
});

test('stats.activeTenants includes only the authenticated users active connections', function () {
    $me = User::factory()->create();
    createOracleTenantFor($me, ['key' => 'first']);
    createOracleTenantFor($me, ['key' => 'second']);
    createOracleTenantFor(User::factory()->create(), ['key' => 'other']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.activeTenants', 2)
        );
});

test('usage stats aggregate only executions launched by the authenticated user this month', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();
    $sharedQuery = Query::factory()->for($other)->shared()->create();
    $myQuery = Query::factory()->for($me)->create();
    $finishedAt = now()->startOfMonth()->addDay();

    foreach ([
        [QueryExecution::STATUS_SUCCEEDED, 100],
        [QueryExecution::STATUS_FAILED, 200],
        [QueryExecution::STATUS_SUCCEEDED, 300],
    ] as [$status, $duration]) {
        QueryExecution::factory()->create([
            'query_id' => $sharedQuery->id,
            'user_id' => $me->id,
            'status' => $status,
            'duration_ms' => $duration,
            'started_at' => $finishedAt->copy()->subMilliseconds($duration),
            'finished_at' => $finishedAt,
        ]);
    }

    QueryExecution::factory()->create([
        'query_id' => $sharedQuery->id,
        'user_id' => $me->id,
        'duration_ms' => 900,
        'started_at' => now()->startOfMonth()->subMinute(),
        'finished_at' => now()->startOfMonth()->subSecond(),
    ]);

    QueryExecution::factory()->create([
        'query_id' => $myQuery->id,
        'user_id' => $other->id,
        'duration_ms' => 1200,
        'started_at' => $finishedAt->copy()->subMilliseconds(1200),
        'finished_at' => $finishedAt,
    ]);

    QueryExecution::factory()
        ->forQueryTemplate()
        ->preview()
        ->create([
            'user_id' => $me->id,
            'duration_ms' => 5000,
            'started_at' => $finishedAt->copy()->subMilliseconds(5000),
            'finished_at' => $finishedAt,
        ]);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.executionsThisMonth', 3)
            ->where('stats.successRate', 66.7)
            ->where('stats.averageDurationMs', 200)
        );
});

test('usage stats return zeros when the authenticated user has no executions this month', function () {
    $me = createConnectedUser();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.executionsThisMonth', 0)
            ->where('stats.successRate', 0)
            ->where('stats.averageDurationMs', 0)
        );
});

test('recentQueries contains at most 5 entries', function () {
    $me = createConnectedUser();
    Query::factory()->count(8)->for($me)->create();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->count() <= 5)
        );
});

test('recentQueries includes shared queries from other users', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();
    Query::factory()->for($other)->shared()->create(['name' => 'Shared one']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->pluck('name')->contains('Shared one'))
        );
});

test('recentQueries does NOT include private queries from other users', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();
    Query::factory()->for($other)->private()->create(['name' => 'Hidden']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => ! $q->pluck('name')->contains('Hidden'))
        );
});

test('each recent query row contains the expected keys', function () {
    $me = createConnectedUser();
    Query::factory()->for($me)->create();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->every(
                fn (array $row) => array_key_exists('id', $row)
                    && array_key_exists('name', $row)
                    && array_key_exists('mode', $row)
                    && array_key_exists('access_level', $row)
                    && array_key_exists('can', $row)
            ))
        );
});

test('can.update is true only for the owner\'s queries', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();
    Query::factory()->for($me)->create(['name' => 'Mine']);
    Query::factory()->for($other)->shared()->create(['name' => 'Shared']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->every(
                fn (array $row) => $row['can']['update'] === ($row['owner'] === $me->name)
            ))
        );
});

test('queriesPerWeek returns 8 real weekly counts, newest last', function () {
    $me = createConnectedUser();
    Query::factory()->count(2)->for($me)->create();
    Query::factory()->for($me)->create(['created_at' => now()->subWeeks(2)]);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queriesPerWeek', fn (Collection $weeks) => $weeks->count() === 8
                && $weeks->last() === 2
                && $weeks->get(5) === 1
                && $weeks->sum() === 3)
        );
});

test('domainBreakdown groups accessible queries by catalog domain', function () {
    $me = createConnectedUser();
    Query::factory()->count(2)->for($me)->create(['parameters' => ['resource_key' => 'suppliers', 'limit' => 5]]);
    Query::factory()->for($me)->create(['parameters' => ['resource_key' => 'invoices', 'limit' => 5]]);
    Query::factory()->for($me)->create(['parameters' => ['limit' => 5]]);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('domainBreakdown', fn (Collection $rows) => $rows->first()['domain'] === 'Procurement'
                && $rows->first()['count'] === 2
                && $rows->pluck('domain')->contains('Finance')
                && $rows->pluck('domain')->contains('Autre'))
        );
});

test('domainBreakdown ignores private queries from other users', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();
    Query::factory()->for($other)->private()->create(['parameters' => ['resource_key' => 'suppliers']]);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('domainBreakdown', fn (Collection $rows) => $rows->isEmpty())
        );
});

test('tenants prop lists configured environments', function () {
    $this->actingAs(createConnectedUser([], [
        'key' => 'client_x',
        'label' => 'Client X',
    ]))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenants', fn (Collection $t) => $t->pluck('key')->contains('client_x'))
        );
});

test('dashboard query count stays bounded with many accessible rows', function () {
    $me = createConnectedUser();
    $other = User::factory()->create();
    Query::factory()->count(30)->for($me)->create();
    Query::factory()->count(20)->for($other)->shared()->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertOk();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Two bounded queries resolve current group IDs and applicable group grants.
    expect($queryCount)->toBeLessThanOrEqual(13);
});
