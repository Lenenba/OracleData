<?php

namespace App\Http\Controllers;

use App\Enums\OracleSchemaImpactStatus;
use App\Models\OracleResourceField;
use App\Models\OracleResourceSchemaSnapshot;
use App\Models\OracleSchemaImpact;
use App\Models\OracleTenant;
use App\Models\QueryExecution;
use App\Services\AuditRecorder;
use App\Services\OracleResourceCatalog;
use App\Services\OracleSchemaSynchronizationService;
use App\Services\SemanticCatalogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OracleSchemaController extends Controller
{
    public function index(OracleTenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'key' => $tenant->key,
                'label' => $tenant->label,
            ],
            'resources' => OracleResourceField::query()
                ->whereBelongsTo($tenant, 'oracleTenant')
                ->where('child', '')
                ->orderBy('resource_key')
                ->get()
                ->map(fn (OracleResourceField $resource): array => [
                    'resource_key' => $resource->resource_key,
                    'source' => $resource->source,
                    'title' => $resource->title,
                    'fields' => $resource->fields,
                    'attributes' => $resource->attributes,
                    'schema_hash' => $resource->schema_hash,
                    'discovered_at' => $resource->discovered_at->toISOString(),
                ]),
        ]);
    }

    public function page(
        Request $request,
        OracleTenant $tenant,
        OracleResourceCatalog $catalog,
        SemanticCatalogReader $semanticCatalog,
    ): Response {
        Gate::authorize('view', $tenant);
        $connection = $tenant->authConnections()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first(['verified_at']);
        $resources = $semanticCatalog->currentVersion() === null
            ? $catalog->suggestions()
            : $semanticCatalog->suggestions(app()->getLocale());
        $current = OracleResourceField::query()
            ->whereBelongsTo($tenant, 'oracleTenant')
            ->where('child', '')
            ->get()
            ->mapWithKeys(fn (OracleResourceField $resource): array => [$resource->resource_key => $resource])
            ->all();
        $snapshots = OracleResourceSchemaSnapshot::query()
            ->whereBelongsTo($tenant, 'oracleTenant')
            ->where('child', '')
            ->latest('synced_at')
            ->latest('id')
            ->get()
            ->groupBy('resource_key')
            ->all();
        $impacts = OracleSchemaImpact::query()
            ->whereBelongsTo($tenant, 'oracleTenant')
            ->get()
            ->groupBy('oracle_resource_schema_snapshot_id')
            ->all();
        $executions = QueryExecution::query()
            ->whereBelongsTo($tenant, 'oracleTenant')
            ->get(['id', 'query_id', 'query_template_version_id']);
        $rows = collect($resources)->map(function (array $definition) use (
            $current,
            $snapshots,
            $impacts,
            $executions,
        ): array {
            $resourceKey = (string) $definition['key'];
            $observed = $current[$resourceKey] ?? null;
            /** @var Collection<int, OracleResourceSchemaSnapshot> $history */
            $history = $snapshots[$resourceKey] ?? collect();
            $latest = $history->first();
            /** @var Collection<int, OracleSchemaImpact> $latestImpacts */
            $latestImpacts = $latest === null ? collect() : ($impacts[$latest->id] ?? collect());
            $diff = $latest === null
                ? ['added' => [], 'removed' => [], 'changed' => []]
                : $latest->diff;
            $hasDrift = $latest !== null && $latest->previous_snapshot_id !== null
                && collect($diff)->contains(fn (array $fields): bool => $fields !== []);
            $hasOpenImpact = $latestImpacts->contains(
                fn (OracleSchemaImpact $impact): bool => $impact->status === OracleSchemaImpactStatus::Open,
            );
            $acknowledgedAt = $latestImpacts->max('acknowledged_at');
            $queryIds = $latestImpacts->pluck('query_id')->filter()->unique()->values();
            $templateVersionIds = $latestImpacts->pluck('query_template_version_id')->filter()->unique()->values();
            $executionCount = $executions
                ->filter(fn (QueryExecution $execution): bool => $queryIds->contains($execution->query_id)
                    || $templateVersionIds->contains($execution->query_template_version_id))
                ->count();
            $status = $observed === null
                ? 'never_synced'
                : ($hasDrift
                    ? ($latestImpacts->isNotEmpty() && ! $hasOpenImpact ? 'acknowledged' : 'changed')
                    : 'current');

            return [
                'resource_key' => $resourceKey,
                'label' => (string) ($definition['label'] ?? $resourceKey),
                'domain' => (string) ($definition['domain'] ?? ''),
                'api_path' => (string) ($definition['path'] ?? ''),
                'source' => $observed?->source,
                'title' => $observed?->title,
                'fields' => $observed === null ? [] : $observed->fields,
                'attributes' => $observed === null ? [] : $observed->attributes,
                'schema_hash' => $observed?->schema_hash,
                'discovered_at' => $observed?->discovered_at?->toISOString(),
                'drift_status' => $status,
                'drift' => [
                    'added' => $hasDrift ? $diff['added'] : [],
                    'removed' => $hasDrift ? $diff['removed'] : [],
                    'changed' => $hasDrift ? $diff['changed'] : [],
                    'detected_at' => $hasDrift ? $latest->synced_at->toISOString() : null,
                ],
                'impacts' => [
                    'templates_count' => $templateVersionIds->count(),
                    'queries_count' => $queryIds->count(),
                    'executions_count' => $executionCount,
                    'acknowledged_at' => $acknowledgedAt?->toISOString(),
                ],
                'history' => $history->take(10)->map(fn (OracleResourceSchemaSnapshot $snapshot): array => [
                    'id' => $snapshot->id,
                    'schema_hash' => $snapshot->schema_hash,
                    'synced_at' => $snapshot->synced_at->toISOString(),
                    'source' => $snapshot->source,
                    'diff' => $snapshot->previous_snapshot_id === null
                        ? ['added' => [], 'removed' => [], 'changed' => []]
                        : $snapshot->diff,
                ])->values()->all(),
            ];
        })->values();

        return Inertia::render('oracle-tenants/schema', [
            'tenant' => [
                'id' => $tenant->id,
                'key' => $tenant->key,
                'label' => $tenant->label,
                'is_active' => $tenant->is_active,
                'verified_at' => $connection?->verified_at?->toISOString(),
            ],
            'resources' => $rows,
            'summary' => [
                'resources' => $rows->count(),
                'synchronized' => $rows->where('drift_status', '!=', 'never_synced')->count(),
                'drifted' => $rows->whereIn('drift_status', ['changed', 'acknowledged'])->count(),
                'impacted' => $rows->filter(fn (array $row): bool => $row['impacts']['queries_count'] > 0
                    || $row['impacts']['templates_count'] > 0)->count(),
            ],
            'capabilities' => [
                'synchronize' => $request->user()->can('update', $tenant),
                'acknowledge_impacts' => $request->user()->can('update', $tenant),
            ],
        ]);
    }

    public function sync(
        Request $request,
        OracleTenant $tenant,
        OracleResourceCatalog $catalog,
        OracleSchemaSynchronizationService $synchronization,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $tenant);

        $validated = $request->validate([
            'resource_key' => ['required', 'string', Rule::in(array_column($catalog->all(), 'key'))],
        ]);
        $result = $synchronization->synchronize($request->user(), $tenant, $validated['resource_key']);

        $payload = [
            'status' => $result['status'],
            'resource' => [
                'resource_key' => $result['resource']->resource_key,
                'source' => $result['resource']->source,
                'title' => $result['resource']->title,
                'fields' => $result['resource']->fields,
                'attributes' => $result['resource']->attributes,
                'schema_hash' => $result['resource']->schema_hash,
                'discovered_at' => $result['resource']->discovered_at->toISOString(),
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($result['status']) {
                'changed' => __('Schéma synchronisé; une dérive a été détectée.'),
                'created' => __('Schéma Oracle synchronisé.'),
                default => __('Le schéma Oracle est déjà à jour.'),
            },
        ]);

        return back();
    }

    public function acknowledgeImpacts(
        Request $request,
        OracleTenant $tenant,
        string $resourceKey,
        OracleResourceCatalog $catalog,
        AuditRecorder $audit,
    ): RedirectResponse {
        Gate::authorize('update', $tenant);

        if ($catalog->find($resourceKey) === null) {
            abort(404);
        }

        $count = DB::transaction(function () use ($request, $tenant, $resourceKey, $audit): int {
            $impacts = OracleSchemaImpact::query()
                ->whereBelongsTo($tenant, 'oracleTenant')
                ->where('resource_key', $resourceKey)
                ->where('status', OracleSchemaImpactStatus::Open)
                ->lockForUpdate()
                ->get();

            foreach ($impacts as $impact) {
                $impact->update([
                    'status' => OracleSchemaImpactStatus::Acknowledged,
                    'acknowledged_by_user_id' => $request->user()->id,
                    'acknowledged_at' => now(),
                ]);
            }

            $audit->record($request->user(), 'oracle.schema_impacts_acknowledged', $tenant, [
                'tenant_id' => $tenant->id,
                'resource_key' => $resourceKey,
                'impact_count' => $impacts->count(),
            ]);

            return $impacts->count();
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('{0} Aucun impact ouvert.|{1} Un impact a été acquitté.|[2,*] :count impacts ont été acquittés.', $count, ['count' => $count]),
        ]);

        return back();
    }
}
