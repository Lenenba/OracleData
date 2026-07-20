<?php

namespace App\Http\Controllers;

use App\Enums\WebhookEvent;
use App\Http\Requests\StoreQueryScheduleRequest;
use App\Http\Requests\UpdateQueryScheduleRequest;
use App\Models\Query;
use App\Models\QueryAlert;
use App\Models\QuerySchedule;
use App\Models\QueryScheduleRun;
use App\Models\WebhookEndpoint;
use App\Services\FusionManager;
use App\Services\NextRunCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Lets a user govern their own recurring query executions. A schedule always
 * runs in the owner's connection scope; cross-user access is refused with 404.
 */
class QueryScheduleController extends Controller
{
    public function index(Request $request, FusionManager $fusion): InertiaResponse
    {
        $user = $request->user();
        $scopedFusion = $fusion->forUser($user);

        $schedules = QuerySchedule::query()
            ->where('user_id', $user->id)
            ->with([
                'executedQuery:id,name',
                'runs' => fn ($query) => $query->latest('ran_at')->limit(1),
                'alerts' => fn ($query) => $query->latest('id'),
            ])
            ->latest('id')
            ->get()
            ->map(fn (QuerySchedule $schedule): array => $this->schedulePayload($schedule))
            ->all();

        $queries = Query::query()
            ->where('user_id', $user->id)
            ->where('mode', '!=', 'agent')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Query $query): array => ['id' => $query->id, 'name' => $query->name])
            ->all();

        $webhooks = WebhookEndpoint::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(fn (WebhookEndpoint $endpoint): array => [
                'id' => $endpoint->id,
                'name' => $endpoint->name,
                'url' => $endpoint->url,
                'events' => $endpoint->events,
                'is_active' => $endpoint->is_active,
                'last_delivered_at' => $endpoint->last_delivered_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('settings/automation', [
            'schedules' => $schedules,
            'schedulableQueries' => $queries,
            'tenants' => $scopedFusion->available(),
            'defaultTenant' => $scopedFusion->defaultKey(),
            'webhooks' => $webhooks,
            'webhookEvents' => WebhookEvent::values(),
        ]);
    }

    public function store(
        StoreQueryScheduleRequest $request,
        FusionManager $fusion,
        NextRunCalculator $calculator,
    ): RedirectResponse {
        $data = $request->validated();
        $query = Query::query()->whereKey($data['query_id'])->firstOrFail();
        Gate::authorize('execute', $query);

        abort_if(
            $query->mode === 'agent',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Les analyses agent ne peuvent pas être planifiées.'),
        );

        $scopedFusion = $fusion->forUser($request->user());
        $tenantKey = (string) ($data['tenant'] ?? $scopedFusion->defaultKey());

        abort_unless(
            $scopedFusion->has($tenantKey),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('Configurez une connexion Oracle active avant de planifier.'),
        );

        $schedule = new QuerySchedule([
            'query_id' => $query->id,
            'name' => $data['name'],
            'tenant_key' => $tenantKey,
            'frequency' => $data['frequency'],
            'time_of_day' => $data['time_of_day'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'timezone' => $request->user()->timezone ?? 'UTC',
            'is_active' => true,
        ]);
        $schedule->user_id = $request->user()->id;
        $schedule->oracle_tenant_id = $request->user()->oracleTenants()->where('key', $tenantKey)->value('id');
        $schedule->next_run_at = $calculator->next($schedule);
        $schedule->save();

        return to_route('automation.index');
    }

    public function update(
        UpdateQueryScheduleRequest $request,
        QuerySchedule $querySchedule,
        NextRunCalculator $calculator,
    ): RedirectResponse {
        abort_unless(
            $querySchedule->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $data = $request->validated();
        $querySchedule->fill([
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'time_of_day' => $data['time_of_day'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'is_active' => $data['is_active'],
        ]);
        $querySchedule->next_run_at = $querySchedule->is_active
            ? $calculator->next($querySchedule)
            : null;
        $querySchedule->save();

        return to_route('automation.index');
    }

    public function destroy(Request $request, QuerySchedule $querySchedule): RedirectResponse
    {
        abort_unless(
            $querySchedule->user_id === (int) $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $querySchedule->delete();

        return to_route('automation.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(QuerySchedule $schedule): array
    {
        /** @var QueryScheduleRun|null $lastRun */
        $lastRun = $schedule->runs->first();

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'query' => [
                'id' => $schedule->executedQuery->id,
                'name' => $schedule->executedQuery->name,
            ],
            'tenant_key' => $schedule->tenant_key,
            'frequency' => $schedule->frequency->value,
            'time_of_day' => $schedule->time_of_day,
            'day_of_week' => $schedule->day_of_week,
            'timezone' => $schedule->timezone,
            'is_active' => $schedule->is_active,
            'last_status' => $schedule->last_status,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_run' => $lastRun === null ? null : [
                'status' => $lastRun->status,
                'row_count' => $lastRun->row_count,
                'ran_at' => $lastRun->ran_at->toIso8601String(),
            ],
            'alerts' => $schedule->alerts->map(fn (QueryAlert $alert): array => [
                'id' => $alert->id,
                'name' => $alert->name,
                'condition' => $alert->condition->value,
                'threshold' => $alert->threshold,
                'is_active' => $alert->is_active,
                'last_triggered_at' => $alert->last_triggered_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
