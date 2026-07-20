<?php

use App\Enums\AgentAnalysisRunStatus;
use App\Exceptions\AgentAnalysisCancelled;
use App\Jobs\RunAgentAnalysis;
use App\Models\AgentAnalysisRun;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\User;
use App\Services\QueryAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('services.anthropic', [
        'api_key' => 'sk-test',
        'base_url' => 'https://api.anthropic.com',
        'model' => 'claude-opus-4-8',
        'version' => '2023-06-01',
    ]);

    $this->runner = User::factory()->create();
    $this->clientX = createOracleTenantFor($this->runner, [
        'key' => 'client_x',
        'label' => 'Client X',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ], [
        'identifier' => 'svc_x',
        'secret' => 'secret_x',
    ]);
});

/** Fake a two-step agent (one Oracle read, then a final result). */
function fakeSuccessfulAgent(): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 'r1', 'name' => 'oracle_query', 'input' => ['resource' => 'suppliers']]], 'stop_reason' => 'tool_use'])
            ->push(['content' => [['type' => 'tool_use', 'id' => 'r2', 'name' => 'submit_result', 'input' => [
                'columns' => ['Supplier'],
                'rows' => [['Supplier' => 'Acme']],
                'analysis' => 'ok',
            ]]], 'stop_reason' => 'tool_use']),
        'client-x.fa.oraclecloud.com/*' => Http::response(['items' => [['Supplier' => 'Acme']], 'count' => 1]),
    ]);
}

function agentQueryFor(User $user, ?int $tenantId): Query
{
    return Query::factory()->for($user)->agent()->create([
        'tenant_key' => 'client_x',
        'oracle_tenant_id' => $tenantId,
    ]);
}

test('the owner queues an agent analysis and receives a queued run', function () {
    Queue::fake();
    $query = agentQueryFor($this->runner, $this->clientX->id);

    $this->actingAs($this->runner)
        ->postJson(route('queries.agent-runs.store', $query), ['tenant' => 'client_x'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('result', null);

    Queue::assertPushed(RunAgentAnalysis::class);

    $run = AgentAnalysisRun::query()->sole();
    expect($run->user_id)->toBe($this->runner->id)
        ->and($run->query_id)->toBe($query->id)
        ->and($run->status)->toBe(AgentAnalysisRunStatus::Queued)
        ->and($run->oracle_tenant_id)->toBe($this->clientX->id);

    expect(AuditEvent::query()->where('action', 'query.agent_dispatched')->exists())->toBeTrue();
});

test('a single-resource query cannot be queued as an agent analysis', function () {
    Queue::fake();
    $query = Query::factory()->for($this->runner)->create([
        'tenant_key' => 'client_x',
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    $this->actingAs($this->runner)
        ->postJson(route('queries.agent-runs.store', $query), ['tenant' => 'client_x'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('queuing is blocked without an active personal connection', function () {
    Queue::fake();
    $stranded = User::factory()->create();
    $query = agentQueryFor($stranded, null);

    $this->actingAs($stranded)
        ->postJson(route('queries.agent-runs.store', $query))
        ->assertStatus(422);

    Queue::assertNothingPushed();
    expect(AgentAnalysisRun::query()->count())->toBe(0);
});

test('a user cannot queue an analysis on another user Oracle connection', function () {
    Queue::fake();
    $other = User::factory()->create();
    createOracleTenantFor($other, [
        'key' => 'client_b',
        'label' => 'Client B',
        'base_url' => 'https://client-b.fa.oraclecloud.com',
        'is_default' => true,
    ], ['identifier' => 'svc_b', 'secret' => 'secret_b']);
    $query = Query::factory()->for($other)->agent()->create(['tenant_key' => 'client_b']);

    // 'client_x' belongs to the runner, not to $other: the scoped tenant rule rejects it.
    $this->actingAs($other)
        ->postJson(route('queries.agent-runs.store', $query), ['tenant' => 'client_x'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('a run is only visible to its owner', function () {
    $run = AgentAnalysisRun::factory()->for($this->runner)->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('agent-runs.show', $run))
        ->assertNotFound();

    $this->actingAs($this->runner)
        ->getJson(route('agent-runs.show', $run))
        ->assertOk()
        ->assertJsonPath('id', $run->id);
});

test('a run is only cancellable by its owner', function () {
    $run = AgentAnalysisRun::factory()->for($this->runner)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('agent-runs.cancel', $run))
        ->assertNotFound();
});

test('the owner polls a run and sees its progress', function () {
    $run = AgentAnalysisRun::factory()->for($this->runner)->running()->create();

    $this->actingAs($this->runner)
        ->getJson(route('agent-runs.show', $run))
        ->assertOk()
        ->assertJsonPath('status', 'running')
        ->assertJsonPath('iteration', 1)
        ->assertJsonPath('oracle_calls_count', 1);
});

test('the job completes the analysis, stores the result and records a successful execution', function () {
    fakeSuccessfulAgent();
    $query = agentQueryFor($this->runner, $this->clientX->id);
    $run = AgentAnalysisRun::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    app()->call([new RunAgentAnalysis($run, 'client_x'), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(AgentAnalysisRunStatus::Completed)
        ->and($run->result['analysis'])->toBe('ok')
        ->and($run->result['columns'])->toBe(['Supplier'])
        ->and($run->row_count)->toBe(1)
        ->and($run->query_execution_id)->not->toBeNull()
        ->and($run->oracle_tenant_id)->toBe($this->clientX->id);

    $execution = QueryExecution::query()->whereKey($run->query_execution_id)->sole();
    expect($execution->status)->toBe(QueryExecution::STATUS_SUCCEEDED)
        ->and($execution->user_id)->toBe($this->runner->id);

    expect(AuditEvent::query()->where('action', 'query.agent_completed')->exists())->toBeTrue();
});

test('a cancellation requested before pickup stops the run without recording an execution', function () {
    $query = agentQueryFor($this->runner, $this->clientX->id);
    $run = AgentAnalysisRun::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'cancel_requested_at' => now(),
    ]);

    app()->call([new RunAgentAnalysis($run, 'client_x'), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(AgentAnalysisRunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull();

    expect(QueryExecution::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('action', 'query.agent_cancelled')->exists())->toBeTrue();
    Http::assertNothingSent();
});

test('the agent aborts cooperatively when cancellation is requested mid-run', function () {
    $abort = fn (): bool => app(QueryAgent::class)
        ->forUser($this->runner)
        ->run('client_x', 'intent', null, fn (): bool => true);

    expect($abort)->toThrow(AgentAnalysisCancelled::class);
    Http::assertNothingSent();
});

test('the job records a failed execution when the analysis fails', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => 'je ne peux pas répondre']],
        'stop_reason' => 'end_turn',
    ])]);
    $query = agentQueryFor($this->runner, $this->clientX->id);
    $run = AgentAnalysisRun::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    app()->call([new RunAgentAnalysis($run, 'client_x'), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(AgentAnalysisRunStatus::Failed)
        ->and($run->error_code)->toBe('agent_error')
        ->and($run->query_execution_id)->not->toBeNull();

    $execution = QueryExecution::query()->whereKey($run->query_execution_id)->sole();
    expect($execution->status)->toBe(QueryExecution::STATUS_FAILED);

    expect(AuditEvent::query()->where('action', 'query.agent_failed')->exists())->toBeTrue();
});

test('a queued run is cancelled immediately by its owner', function () {
    $run = AgentAnalysisRun::factory()->for($this->runner)->create();

    $this->actingAs($this->runner)
        ->postJson(route('agent-runs.cancel', $run))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect($run->refresh()->finished_at)->not->toBeNull();
    expect(AuditEvent::query()->where('action', 'query.agent_cancelled')->exists())->toBeTrue();
});

test('a large agent result is capped while the row count stays accurate', function () {
    $rows = [];
    for ($index = 0; $index < AgentAnalysisRun::MAX_RESULT_ROWS + 1; $index++) {
        $rows[] = ['n' => $index];
    }

    Http::fake(['api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'tool_use', 'id' => 's1', 'name' => 'submit_result', 'input' => [
            'columns' => ['n'],
            'rows' => $rows,
            'analysis' => 'big',
        ]]],
        'stop_reason' => 'tool_use',
    ])]);
    $query = agentQueryFor($this->runner, $this->clientX->id);
    $run = AgentAnalysisRun::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    app()->call([new RunAgentAnalysis($run, 'client_x'), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(AgentAnalysisRunStatus::Completed)
        ->and($run->row_count)->toBe(AgentAnalysisRun::MAX_RESULT_ROWS + 1)
        ->and($run->result['truncated'])->toBeTrue()
        ->and($run->result['count'])->toBe(AgentAnalysisRun::MAX_RESULT_ROWS + 1)
        ->and(count($run->result['items']))->toBe(AgentAnalysisRun::MAX_RESULT_ROWS);
});
