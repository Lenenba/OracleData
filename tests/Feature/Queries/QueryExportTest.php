<?php

use App\Enums\QueryExportStatus;
use App\Jobs\RunQueryExport;
use App\Models\AuditEvent;
use App\Models\Query;
use App\Models\QueryExport;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
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

function exportableQueryFor(User $user, ?int $tenantId): Query
{
    return Query::factory()->for($user)->create([
        'resource_path' => '/hcmRestApi/resources/11.13.18.05/workers',
        'tenant_key' => 'client_x',
        'oracle_tenant_id' => $tenantId,
        'parameters' => ['limit' => 25],
    ]);
}

function runExportJob(QueryExport $export): void
{
    app()->call([new RunQueryExport($export, 'client_x'), 'handle']);
}

test('the owner queues a server export and receives a queued record', function () {
    Queue::fake();
    $query = exportableQueryFor($this->runner, $this->clientX->id);

    $this->actingAs($this->runner)
        ->postJson(route('queries.exports.store', $query), ['tenant' => 'client_x'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('downloadable', false);

    Queue::assertPushed(RunQueryExport::class);

    $export = QueryExport::query()->sole();
    expect($export->user_id)->toBe($this->runner->id)
        ->and($export->query_id)->toBe($query->id)
        ->and($export->max_rows)->toBe(QueryExport::MAX_ROWS)
        ->and($export->oracle_tenant_id)->toBe($this->clientX->id);

    expect(AuditEvent::query()->where('action', 'query.export_dispatched')->exists())->toBeTrue();
});

test('an agent query cannot be exported server-side', function () {
    Queue::fake();
    $query = Query::factory()->for($this->runner)->agent()->create(['tenant_key' => 'client_x']);

    $this->actingAs($this->runner)
        ->postJson(route('queries.exports.store', $query), ['tenant' => 'client_x'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('exporting is blocked without an active personal connection', function () {
    Queue::fake();
    $stranded = User::factory()->create();
    $query = Query::factory()->for($stranded)->create(['oracle_tenant_id' => null]);

    $this->actingAs($stranded)
        ->postJson(route('queries.exports.store', $query))
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('a user cannot export on another user Oracle connection', function () {
    Queue::fake();
    $other = User::factory()->create();
    createOracleTenantFor($other, [
        'key' => 'client_b',
        'label' => 'Client B',
        'base_url' => 'https://client-b.fa.oraclecloud.com',
        'is_default' => true,
    ], ['identifier' => 'svc_b', 'secret' => 'secret_b']);
    $query = Query::factory()->for($other)->create(['tenant_key' => 'client_b']);

    $this->actingAs($other)
        ->postJson(route('queries.exports.store', $query), ['tenant' => 'client_x'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('an export is only visible to its owner', function () {
    $export = QueryExport::factory()->for($this->runner)->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('exports.show', $export))
        ->assertNotFound();

    $this->actingAs($this->runner)
        ->getJson(route('exports.show', $export))
        ->assertOk()
        ->assertJsonPath('id', $export->id);
});

test('an export is only cancellable by its owner', function () {
    $export = QueryExport::factory()->for($this->runner)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('exports.cancel', $export))
        ->assertNotFound();
});

test('the job streams the full result to a CSV file and records completion', function () {
    Storage::fake('local');
    Http::fake(['client-x.fa.oraclecloud.com/*' => Http::response([
        'items' => [['PersonId' => 1], ['PersonId' => 2]],
        'count' => 2,
        'hasMore' => false,
    ])]);
    $query = exportableQueryFor($this->runner, $this->clientX->id);
    $export = QueryExport::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    runExportJob($export);

    $export->refresh();
    expect($export->status)->toBe(QueryExportStatus::Completed)
        ->and($export->row_count)->toBe(2)
        ->and($export->truncated)->toBeFalse()
        ->and($export->file_path)->not->toBeNull()
        ->and($export->expires_at)->not->toBeNull();

    Storage::disk('local')->assertExists($export->file_path);
    $content = Storage::disk('local')->get($export->file_path);
    expect($content)->toContain('PersonId')
        ->and($content)->toContain('1')
        ->and($content)->toContain('2');

    expect(AuditEvent::query()->where('action', 'query.export_completed')->exists())->toBeTrue();
});

test('the job paginates Oracle across multiple pages', function () {
    Storage::fake('local');
    Http::fake(['client-x.fa.oraclecloud.com/*' => Http::sequence()
        ->push(['items' => [['PersonId' => 1], ['PersonId' => 2]], 'hasMore' => true])
        ->push(['items' => [['PersonId' => 3]], 'hasMore' => false]),
    ]);
    $query = exportableQueryFor($this->runner, $this->clientX->id);
    $export = QueryExport::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    runExportJob($export);

    expect($export->refresh()->row_count)->toBe(3)
        ->and($export->truncated)->toBeFalse();
});

test('the job stops at the row cap and flags truncation', function () {
    Storage::fake('local');
    Http::fake(['client-x.fa.oraclecloud.com/*' => Http::response([
        'items' => [['PersonId' => 1], ['PersonId' => 2], ['PersonId' => 3]],
        'hasMore' => true,
    ])]);
    $query = exportableQueryFor($this->runner, $this->clientX->id);
    $export = QueryExport::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
        'max_rows' => 2,
    ]);

    runExportJob($export);

    $export->refresh();
    expect($export->status)->toBe(QueryExportStatus::Completed)
        ->and($export->row_count)->toBe(2)
        ->and($export->truncated)->toBeTrue();
});

test('a cancellation requested before pickup stops the export without a file', function () {
    Storage::fake('local');
    $query = exportableQueryFor($this->runner, $this->clientX->id);
    $export = QueryExport::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'cancel_requested_at' => now(),
    ]);

    runExportJob($export);

    $export->refresh();
    expect($export->status)->toBe(QueryExportStatus::Cancelled)
        ->and($export->file_path)->toBeNull();

    expect(AuditEvent::query()->where('action', 'query.export_cancelled')->exists())->toBeTrue();
    Http::assertNothingSent();
});

test('the job records a failure when the connection cannot be resolved', function () {
    Storage::fake('local');
    $query = exportableQueryFor($this->runner, $this->clientX->id);
    $export = QueryExport::factory()->for($this->runner)->create([
        'query_id' => $query->id,
        'oracle_tenant_id' => $this->clientX->id,
    ]);

    // A tenant key the user does not own resolves to no client and fails closed.
    app()->call([new RunQueryExport($export, 'client_missing'), 'handle']);

    $export->refresh();
    expect($export->status)->toBe(QueryExportStatus::Failed)
        ->and($export->error_code)->toBe('export_error')
        ->and($export->file_path)->toBeNull();

    expect(AuditEvent::query()->where('action', 'query.export_failed')->exists())->toBeTrue();
});

test('a queued export is cancelled immediately by its owner', function () {
    $export = QueryExport::factory()->for($this->runner)->create();

    $this->actingAs($this->runner)
        ->postJson(route('exports.cancel', $export))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect(AuditEvent::query()->where('action', 'query.export_cancelled')->exists())->toBeTrue();
});

test('the owner downloads a completed export, others and expired ones get 404', function () {
    Storage::fake('local');
    Storage::disk('local')->put('exports/example.csv', "\xEF\xBB\xBFPersonId\r\n1\r\n");
    $export = QueryExport::factory()->for($this->runner)->completed('exports/example.csv')->create();

    $this->actingAs($this->runner)
        ->get(route('exports.download', $export))
        ->assertOk()
        ->assertDownload('export-'.$export->id.'.csv');

    $this->actingAs(User::factory()->create())
        ->get(route('exports.download', $export))
        ->assertNotFound();

    $expired = QueryExport::factory()->for($this->runner)->expired()->create([
        'file_path' => 'exports/example.csv',
    ]);
    $this->actingAs($this->runner)
        ->get(route('exports.download', $expired))
        ->assertNotFound();
});

test('the purge command removes expired export files and records', function () {
    Storage::fake('local');
    Storage::disk('local')->put('exports/old.csv', 'x');
    Storage::disk('local')->put('exports/fresh.csv', 'y');
    $expired = QueryExport::factory()->for($this->runner)->expired()->create([
        'file_path' => 'exports/old.csv',
    ]);
    $fresh = QueryExport::factory()->for($this->runner)->completed('exports/fresh.csv')->create();

    $this->artisan('exports:purge')->assertSuccessful();

    expect(QueryExport::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(QueryExport::query()->whereKey($fresh->id)->exists())->toBeTrue();
    Storage::disk('local')->assertMissing('exports/old.csv');
    Storage::disk('local')->assertExists('exports/fresh.csv');
});
