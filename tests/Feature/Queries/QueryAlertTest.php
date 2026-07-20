<?php

use App\Enums\AlertCondition;
use App\Jobs\RunScheduledQuery;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryAlert;
use App\Models\QueryAlertEvent;
use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Models\User;
use App\Services\QueryAlertEvaluator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->schedule = QuerySchedule::factory()->for($this->owner)->create();
});

function successfulRun(QuerySchedule $schedule, User $owner, int $rows): QueryScheduleRun
{
    return QueryScheduleRun::factory()->for($owner)->create([
        'query_schedule_id' => $schedule->id,
        'status' => QuerySchedule::STATUS_SUCCEEDED,
        'row_count' => $rows,
    ]);
}

test('a row-count-above alert triggers, records an event and notifies the owner', function () {
    $alert = QueryAlert::factory()->for($this->owner)->condition(AlertCondition::RowCountAbove, 5)
        ->create(['query_schedule_id' => $this->schedule->id]);
    $run = successfulRun($this->schedule, $this->owner, 10);

    app(QueryAlertEvaluator::class)->evaluate($this->schedule, $run);

    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->where('observed_value', 10)->exists())->toBeTrue()
        ->and($alert->refresh()->last_triggered_at)->not->toBeNull()
        ->and($this->owner->notifications()->where('type', 'query_alert_triggered')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'query.alert_triggered')->exists())->toBeTrue();
});

test('a row-count-below alert triggers only when the run is under the threshold', function () {
    $alert = QueryAlert::factory()->for($this->owner)->condition(AlertCondition::RowCountBelow, 5)
        ->create(['query_schedule_id' => $this->schedule->id]);

    app(QueryAlertEvaluator::class)->evaluate($this->schedule, successfulRun($this->schedule, $this->owner, 2));
    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->count())->toBe(1);

    app(QueryAlertEvaluator::class)->evaluate($this->schedule, successfulRun($this->schedule, $this->owner, 9));
    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->count())->toBe(1);
});

test('a run-failed alert triggers on a failed run', function () {
    $alert = QueryAlert::factory()->for($this->owner)->condition(AlertCondition::RunFailed)
        ->create(['query_schedule_id' => $this->schedule->id]);
    $run = QueryScheduleRun::factory()->for($this->owner)->failed()
        ->create(['query_schedule_id' => $this->schedule->id]);

    app(QueryAlertEvaluator::class)->evaluate($this->schedule, $run);

    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->exists())->toBeTrue();
});

test('an inactive alert never triggers', function () {
    $alert = QueryAlert::factory()->for($this->owner)->inactive()->condition(AlertCondition::RowCountAbove, 1)
        ->create(['query_schedule_id' => $this->schedule->id]);

    app(QueryAlertEvaluator::class)->evaluate($this->schedule, successfulRun($this->schedule, $this->owner, 10));

    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->exists())->toBeFalse();
});

test('a non-matching condition does not trigger', function () {
    $alert = QueryAlert::factory()->for($this->owner)->condition(AlertCondition::RowCountAbove, 100)
        ->create(['query_schedule_id' => $this->schedule->id]);

    app(QueryAlertEvaluator::class)->evaluate($this->schedule, successfulRun($this->schedule, $this->owner, 10));

    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->exists())->toBeFalse();
});

test('the owner can add an alert to their schedule', function () {
    $this->actingAs($this->owner)
        ->post(route('alerts.store', $this->schedule), [
            'name' => 'Trop de lignes',
            'condition' => 'row_count_above',
            'threshold' => 100,
        ])
        ->assertRedirect(route('automation.index'));

    expect(QueryAlert::query()->where('query_schedule_id', $this->schedule->id)->exists())->toBeTrue();
});

test('a row-count condition requires a threshold', function () {
    $this->actingAs($this->owner)
        ->post(route('alerts.store', $this->schedule), [
            'name' => 'Sans seuil',
            'condition' => 'row_count_above',
        ])
        ->assertSessionHasErrors('threshold');
});

test('a user cannot add an alert to another user schedule', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('alerts.store', $this->schedule), [
            'name' => 'Pirate',
            'condition' => 'run_failed',
        ])
        ->assertNotFound();
});

test('an alert cannot be updated or deleted by another user', function () {
    $alert = QueryAlert::factory()->for($this->owner)->create(['query_schedule_id' => $this->schedule->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patch(route('alerts.update', $alert), [
            'name' => 'Pirate',
            'condition' => 'run_failed',
        ])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->delete(route('alerts.destroy', $alert))
        ->assertNotFound();
});

test('a scheduled run evaluates alerts end to end', function () {
    $owner = User::factory()->create();
    $tenant = createOracleTenantFor($owner, [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ], ['identifier' => 'svc_x', 'secret' => 'secret_x']);
    $query = Query::factory()->for($owner)->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'oracle_tenant_id' => $tenant->id,
        'parameters' => ['limit' => 25],
    ]);
    $schedule = QuerySchedule::factory()->for($owner)->due()->create([
        'query_id' => $query->id,
        'tenant_key' => 'client_x',
    ]);
    $alert = QueryAlert::factory()->for($owner)->condition(AlertCondition::RowCountAbove, 1)
        ->create(['query_schedule_id' => $schedule->id]);

    Http::fake(['client-x.fa.oraclecloud.com/*' => Http::response([
        'items' => [['PersonId' => 1], ['PersonId' => 2]],
        'count' => 2,
        'hasMore' => false,
    ])]);

    app()->call([new RunScheduledQuery($schedule), 'handle']);

    expect(QueryAlertEvent::query()->where('query_alert_id', $alert->id)->exists())->toBeTrue()
        ->and($owner->notifications()->where('type', 'query_alert_triggered')->exists())->toBeTrue();
});
