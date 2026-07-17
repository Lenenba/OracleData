<?php

use App\Models\OracleTenant;
use App\Models\Query;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('fusion.default', 'client_x');
    config()->set('fusion.tenants', [
        'client_x' => [
            'label' => 'Client X',
            'base_url' => 'https://client-x.fa.oraclecloud.com',
            'username' => 'svc_x',
            'password' => 'secret_x',
        ],
    ]);
});

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the dashboard renders the correct Inertia component', function () {
    $this->actingAs(User::factory()->create())
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
    $me = User::factory()->create();
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
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.activeTenants', 1)
        );
});

test('stats.activeTenants includes database tenants', function () {
    OracleTenant::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.activeTenants', 2)
        );
});

test('recentQueries contains at most 5 entries', function () {
    $me = User::factory()->create();
    Query::factory()->count(8)->for($me)->create();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->count() <= 5)
        );
});

test('recentQueries includes shared queries from other users', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Query::factory()->for($other)->shared()->create(['name' => 'Shared one']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->pluck('name')->contains('Shared one'))
        );
});

test('recentQueries does NOT include private queries from other users', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Query::factory()->for($other)->private()->create(['name' => 'Hidden']);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => ! $q->pluck('name')->contains('Hidden'))
        );
});

test('each recent query row contains the expected keys', function () {
    $me = User::factory()->create();
    Query::factory()->for($me)->create();

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recentQueries', fn (Collection $q) => $q->every(
                fn (array $row) => array_key_exists('id', $row)
                    && array_key_exists('name', $row)
                    && array_key_exists('mode', $row)
                    && array_key_exists('visibility', $row)
                    && array_key_exists('can', $row)
            ))
        );
});

test('can.update is true only for the owner\'s queries', function () {
    $me = User::factory()->create();
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
    $me = User::factory()->create();
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
    $me = User::factory()->create();
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
    $me = User::factory()->create();
    $other = User::factory()->create();
    Query::factory()->for($other)->private()->create(['parameters' => ['resource_key' => 'suppliers']]);

    $this->actingAs($me)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('domainBreakdown', fn (Collection $rows) => $rows->isEmpty())
        );
});

test('tenants prop lists configured environments', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenants', fn (Collection $t) => $t->pluck('key')->contains('client_x'))
        );
});
