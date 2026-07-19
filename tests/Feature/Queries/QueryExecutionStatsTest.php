<?php

use App\Models\Query;
use App\Models\QueryExecution;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function createRunnableQuery(User $user, string $tenantKey = 'client_x'): Query
{
    return Query::factory()->for($user)->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => $tenantKey,
        'parameters' => ['limit' => 25],
    ]);
}

beforeEach(function () {
    $this->runner = User::factory()->create();
    $this->tenant = createOracleTenantFor($this->runner, [
        'key' => 'client_x',
        'base_url' => 'https://client-x.fa.oraclecloud.com',
        'is_default' => true,
    ]);
});

test('a successful run records an execution with the runner tenant and connection', function () {
    Http::fake(['*' => Http::response([
        'items' => [['PersonId' => 1], ['PersonId' => 2]],
        'count' => 2,
        'hasMore' => false,
    ])]);

    $query = createRunnableQuery($this->runner);

    $this->actingAs($this->runner)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();

    $execution = QueryExecution::query()->sole();

    expect($execution->status)->toBe(QueryExecution::STATUS_SUCCEEDED)
        ->and($execution->source_type)->toBe(QueryExecution::SOURCE_SAVED_QUERY)
        ->and($execution->purpose)->toBe(QueryExecution::PURPOSE_RUN)
        ->and($execution->user_id)->toBe($this->runner->id)
        ->and($execution->query_id)->toBe($query->id)
        ->and($execution->oracle_tenant_id)->toBe($this->tenant->id)
        ->and($execution->auth_connection_id)->toBe($this->tenant->authConnections->sole()->id)
        ->and($execution->rows_count)->toBe(2)
        ->and($execution->error_code)->toBeNull()
        ->and($execution->finished_at)->not->toBeNull();
});

test('a successful run updates the query aggregates atomically', function () {
    Http::fake(['*' => Http::response(['items' => [['PersonId' => 1]], 'count' => 1, 'hasMore' => false])]);

    $query = createRunnableQuery($this->runner);

    $this->actingAs($this->runner)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();

    $query->refresh();

    expect($query->execution_count)->toBe(1)
        ->and($query->successful_execution_count)->toBe(1)
        ->and($query->last_executed_at)->not->toBeNull()
        ->and($query->last_successful_execution_at)->not->toBeNull();
});

test('a failed run records a failed execution without touching the success aggregates', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    $query = createRunnableQuery($this->runner);

    $this->actingAs($this->runner)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();

    $execution = QueryExecution::query()->sole();
    $query->refresh();

    expect($execution->status)->toBe(QueryExecution::STATUS_FAILED)
        ->and($execution->error_code)->toBe('oracle_error')
        ->and($execution->rows_count)->toBe(0)
        ->and($query->execution_count)->toBe(1)
        ->and($query->successful_execution_count)->toBe(0)
        ->and($query->last_executed_at)->not->toBeNull()
        ->and($query->last_successful_execution_at)->toBeNull();
});

test('a shared query run by a reader records the reader tenant, never the author one', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0, 'hasMore' => false])]);

    $author = User::factory()->create();
    $authorTenant = createOracleTenantFor($author, [
        'key' => 'author_env',
        'base_url' => 'https://author.fa.oraclecloud.com',
        'is_default' => true,
    ]);
    $reader = User::factory()->create();
    $readerTenant = createOracleTenantFor($reader, [
        'key' => 'reader_env',
        'base_url' => 'https://reader.fa.oraclecloud.com',
        'is_default' => true,
    ]);

    $query = Query::factory()->for($author)->shared()->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'author_env',
        'oracle_tenant_id' => $authorTenant->id,
        'parameters' => ['limit' => 25],
    ]);

    $this->actingAs($reader)
        ->postJson(route('queries.run', $query), ['tenant' => 'reader_env'])
        ->assertOk();

    $execution = QueryExecution::query()->sole();

    expect($execution->user_id)->toBe($reader->id)
        ->and($execution->oracle_tenant_id)->toBe($readerTenant->id)
        ->and($execution->oracle_tenant_id)->not->toBe($authorTenant->id);
});

test('deleting a query keeps its execution history', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0, 'hasMore' => false])]);

    $query = createRunnableQuery($this->runner);

    $this->actingAs($this->runner)
        ->postJson(route('queries.run', $query), ['tenant' => 'client_x'])
        ->assertOk();

    $query->delete();

    $execution = QueryExecution::query()->sole();

    expect($execution->query_id)->toBe($query->id)
        ->and(Query::query()->find($query->id))->toBeNull()
        ->and(Query::withTrashed()->find($query->id))->not->toBeNull()
        ->and($execution->user_id)->toBe($this->runner->id);
});

test('previews do not record executions', function () {
    Http::fake(['*' => Http::response(['items' => [], 'count' => 0, 'hasMore' => false])]);

    $this->actingAs($this->runner)->postJson(route('queries.direct-preview'), [
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant' => 'client_x',
    ]);

    expect(QueryExecution::query()->count())->toBe(0);
});
