<?php

use App\Enums\ScheduleFrequency;
use App\Jobs\RunScheduledQuery;
use App\Models\Query;
use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->clientX = createOracleTenantFor($this->owner, [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ], [
        'identifier' => 'svc_x',
        'secret' => 'secret_x',
    ]);
    $this->query = Query::factory()->for($this->owner)->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'oracle_tenant_id' => $this->clientX->id,
        'parameters' => ['limit' => 25],
    ]);
});

test('the owner can create a daily schedule', function () {
    $this->actingAs($this->owner)
        ->post(route('schedules.store'), [
            'query_id' => $this->query->id,
            'name' => 'Rapport quotidien',
            'tenant' => 'client_x',
            'frequency' => 'daily',
            'time_of_day' => '08:00',
        ])
        ->assertRedirect(route('automation.index'));

    $schedule = QuerySchedule::query()->sole();
    expect($schedule->user_id)->toBe($this->owner->id)
        ->and($schedule->frequency)->toBe(ScheduleFrequency::Daily)
        ->and($schedule->is_active)->toBeTrue()
        ->and($schedule->next_run_at)->not->toBeNull()
        ->and($schedule->oracle_tenant_id)->toBe($this->clientX->id);
});

test('an agent query cannot be scheduled', function () {
    $agentQuery = Query::factory()->for($this->owner)->agent()->create(['tenant_key' => 'client_x']);

    $this->actingAs($this->owner)
        ->post(route('schedules.store'), [
            'query_id' => $agentQuery->id,
            'name' => 'Analyse',
            'tenant' => 'client_x',
            'frequency' => 'daily',
            'time_of_day' => '08:00',
        ])
        ->assertStatus(422);

    expect(QuerySchedule::query()->count())->toBe(0);
});

test('a weekly schedule requires a day of week', function () {
    $this->actingAs($this->owner)
        ->post(route('schedules.store'), [
            'query_id' => $this->query->id,
            'name' => 'Hebdo',
            'tenant' => 'client_x',
            'frequency' => 'weekly',
            'time_of_day' => '08:00',
        ])
        ->assertSessionHasErrors('day_of_week');
});

test('the owner can deactivate a schedule which clears its next run', function () {
    $schedule = QuerySchedule::factory()->for($this->owner)->create([
        'query_id' => $this->query->id,
        'next_run_at' => now()->addDay(),
    ]);

    $this->actingAs($this->owner)
        ->patch(route('schedules.update', $schedule), [
            'name' => $schedule->name,
            'frequency' => 'daily',
            'time_of_day' => '09:00',
            'is_active' => false,
        ])
        ->assertRedirect(route('automation.index'));

    $schedule->refresh();
    expect($schedule->is_active)->toBeFalse()
        ->and($schedule->next_run_at)->toBeNull();
});

test('a schedule cannot be updated or deleted by another user', function () {
    $schedule = QuerySchedule::factory()->for($this->owner)->create(['query_id' => $this->query->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patch(route('schedules.update', $schedule), [
            'name' => 'Pirate',
            'frequency' => 'daily',
            'time_of_day' => '08:00',
            'is_active' => true,
        ])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->delete(route('schedules.destroy', $schedule))
        ->assertNotFound();
});

test('the owner can delete a schedule', function () {
    $schedule = QuerySchedule::factory()->for($this->owner)->create(['query_id' => $this->query->id]);

    $this->actingAs($this->owner)
        ->delete(route('schedules.destroy', $schedule))
        ->assertRedirect(route('automation.index'));

    expect(QuerySchedule::query()->whereKey($schedule->id)->exists())->toBeFalse();
});

test('the command dispatches only due active schedules and advances their next run', function () {
    Queue::fake();
    $due = QuerySchedule::factory()->for($this->owner)->due()->create(['query_id' => $this->query->id]);
    $future = QuerySchedule::factory()->for($this->owner)->create([
        'query_id' => $this->query->id,
        'next_run_at' => now()->addDay(),
    ]);
    $inactive = QuerySchedule::factory()->for($this->owner)->inactive()->create(['query_id' => $this->query->id]);

    $this->artisan('schedules:run-due')->assertSuccessful();

    Queue::assertPushed(RunScheduledQuery::class, 1);
    expect($due->refresh()->next_run_at->isFuture())->toBeTrue()
        ->and($future->refresh()->next_run_at->isFuture())->toBeTrue()
        ->and($inactive->refresh()->next_run_at)->toBeNull();
});

test('the scheduled job records a timestamped run and updates the schedule', function () {
    Http::fake(['client-x.fa.oraclecloud.com/*' => Http::response([
        'items' => [['PersonId' => 1], ['PersonId' => 2]],
        'count' => 2,
        'hasMore' => false,
    ])]);
    $schedule = QuerySchedule::factory()->for($this->owner)->due()->create([
        'query_id' => $this->query->id,
        'tenant_key' => 'client_x',
    ]);

    app()->call([new RunScheduledQuery($schedule), 'handle']);

    $run = QueryScheduleRun::query()->sole();
    expect($run->query_schedule_id)->toBe($schedule->id)
        ->and($run->status)->toBe(QuerySchedule::STATUS_SUCCEEDED)
        ->and($run->row_count)->toBe(2);

    $schedule->refresh();
    expect($schedule->last_status)->toBe(QuerySchedule::STATUS_SUCCEEDED)
        ->and($schedule->last_run_at)->not->toBeNull()
        ->and($schedule->next_run_at)->not->toBeNull();
});

test('the scheduled job records a failure when the connection is gone', function () {
    $schedule = QuerySchedule::factory()->for($this->owner)->due()->create([
        'query_id' => $this->query->id,
        'tenant_key' => 'client_missing',
    ]);

    app()->call([new RunScheduledQuery($schedule), 'handle']);

    $run = QueryScheduleRun::query()->sole();
    expect($run->status)->toBe(QuerySchedule::STATUS_FAILED)
        ->and($run->error_code)->toBe('oracle_error');
    expect($schedule->refresh()->last_status)->toBe(QuerySchedule::STATUS_FAILED);
});

test('the automation settings page renders for the owner', function () {
    QuerySchedule::factory()->for($this->owner)->create(['query_id' => $this->query->id]);

    $this->actingAs($this->owner)
        ->get(route('automation.index'))
        ->assertOk();
});
