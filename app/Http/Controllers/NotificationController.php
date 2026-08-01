<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\QueryUserShare;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse|InertiaResponse
    {
        /** @var array{filter?: string|null, per_page?: int, page?: int} $validated */
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $filter = (string) ($validated['filter'] ?? 'all');
        $perPage = (int) ($validated['per_page'] ?? 20);
        $notifications = $actor->notifications()
            ->when($filter === 'unread', fn (Builder $items) => $items->whereNull('read_at'))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
        $items = $notifications->getCollection();
        $shareIds = $this->technicalIds($items, 'share_id');
        $changeRequestIds = $this->technicalIds($items, 'change_request_id');
        $queryIds = $this->technicalIds($items, 'query_id');
        $relatedUserIds = collect([
            ...$this->technicalIds($items, 'invited_by_user_id'),
            ...$this->technicalIds($items, 'responded_by_user_id'),
            ...$this->technicalIds($items, 'actor_user_id'),
        ])->unique()->values()->all();
        $shares = QueryUserShare::query()
            ->whereKey($shareIds)
            ->with(['sharedByUser:id,name', 'user:id,name'])
            ->get()
            ->keyBy(fn (QueryUserShare $share): int => $share->id);
        $changeRequests = QueryChangeRequest::query()
            ->whereKey($changeRequestIds)
            ->with(['requestedBy:id,name', 'statusChangedBy:id,name'])
            ->get()
            ->keyBy(fn (QueryChangeRequest $changeRequest): int => $changeRequest->id);
        $queries = Query::query()
            ->withTrashed()
            ->select(['id', 'user_id', 'name', 'deleted_at'])
            ->whereKey($queryIds)
            ->get()
            ->keyBy(fn (Query $query): int => $query->id);
        $relatedUsers = User::query()
            ->select(['id', 'name'])
            ->whereKey($relatedUserIds)
            ->get()
            ->keyBy(fn (User $user): int => $user->id);
        $accessibleQueryIds = Query::query()
            ->accessibleTo($actor)
            ->whereKey($queryIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $accessibleQueryIds = [...$accessibleQueryIds];

        $payload = [
            'notifications' => [
                'data' => $items
                    ->map(fn (DatabaseNotification $notification): array => $this->notificationPayload(
                        $notification,
                        $shares->get((int) ($notification->data['share_id'] ?? 0)),
                        $changeRequests->get((int) ($notification->data['change_request_id'] ?? 0)),
                        $queries->get((int) ($notification->data['query_id'] ?? 0)),
                        $relatedUsers,
                        $accessibleQueryIds,
                        $actor,
                    ))
                    ->values()
                    ->all(),
                'meta' => $this->paginationMeta($notifications),
            ],
            'filter' => $filter,
        ];

        return $request->expectsJson()
            ? response()->json($payload)
            : Inertia::render('notifications/index', $payload);
    }

    public function read(Request $request, string $notification): Response|RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var DatabaseNotification $ownedNotification */
        $ownedNotification = $actor->notifications()->whereKey($notification)->firstOrFail();
        $ownedNotification->markAsRead();

        return $request->expectsJson()
            ? response()->noContent()
            : back()->with('status', 'notification-read');
    }

    public function readAll(Request $request): Response|RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $actor->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson()
            ? response()->noContent()
            : back()->with('status', 'notifications-read');
    }

    /**
     * @param  Collection<int, User>  $relatedUsers
     * @param  list<int>  $accessibleQueryIds
     * @return array<string, mixed>
     */
    private function notificationPayload(
        DatabaseNotification $notification,
        ?QueryUserShare $share,
        ?QueryChangeRequest $changeRequest,
        ?Query $query,
        Collection $relatedUsers,
        array $accessibleQueryIds,
        User $actor,
    ): array {
        $type = (string) $notification->type;
        $kind = $this->kind($type);
        $eventActorId = $notification->data['responded_by_user_id']
            ?? $notification->data['actor_user_id']
            ?? $notification->data['invited_by_user_id']
            ?? null;
        $eventActor = is_numeric($eventActorId)
            ? $relatedUsers->get((int) $eventActorId)
            : null;
        $isQueryAccessible = $query !== null && in_array($query->id, $accessibleQueryIds, true);
        $isInvitationAvailable = $kind === 'query_share_invitation'
            && $share?->user_id === $actor->id
            && $share->isPending()
            && $query?->deleted_at === null;
        $isChangeRequestAvailable = str_starts_with($kind, 'query_change_request_')
            && $changeRequest !== null
            && $isQueryAccessible;
        $mayExposeContext = $isQueryAccessible || $isInvitationAvailable;
        $visibleQuery = $mayExposeContext ? $query : null;
        $visibleChangeRequest = $isChangeRequestAvailable ? $changeRequest : null;

        return [
            'id' => $notification->id,
            'type' => $type,
            'kind' => $kind,
            'read_at' => $this->isoDate($notification->read_at),
            'created_at' => $this->isoDate($notification->created_at),
            'actor' => $eventActor === null ? null : [
                'id' => $eventActor->id,
                'name' => $eventActor->name,
            ],
            'query' => $visibleQuery === null ? null : [
                'id' => $visibleQuery->id,
                'name' => $visibleQuery->name,
                'is_archived' => $visibleQuery->deleted_at !== null,
            ],
            'is_available' => $isInvitationAvailable
                || ($kind === 'query_share_invitation' && $isQueryAccessible)
                || $isChangeRequestAvailable
                || (str_starts_with($kind, 'query_share_invitation_') && $isQueryAccessible),
            'share' => $share === null ? null : $this->sharePayload($share, $visibleQuery, $actor),
            'change_request' => $visibleChangeRequest === null ? null : [
                'id' => $visibleChangeRequest->id,
                'title' => $visibleChangeRequest->title,
                'status' => $visibleChangeRequest->status->value,
                'can_view' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sharePayload(QueryUserShare $share, ?Query $query, User $actor): array
    {
        return [
            'id' => $share->id,
            'status' => $this->displayStatus($share),
            'permission' => $share->permission->value,
            'expires_at' => $this->isoDate($share->expires_at),
            'respond_by' => $this->isoDate($share->respond_by),
            'accepted_at' => $this->isoDate($share->accepted_at),
            'declined_at' => $this->isoDate($share->declined_at),
            'cancelled_at' => $this->isoDate($share->cancelled_at),
            'revoked_at' => $this->isoDate($share->revoked_at),
            'is_pending' => $share->isPending(),
            'is_expired' => $this->displayStatus($share) === 'expired',
            'can_accept' => $share->user_id === $actor->id
                && $share->isPending()
                && $query !== null
                && $query->deleted_at === null,
            'can_decline' => $share->user_id === $actor->id
                && $share->isPending()
                && $query !== null
                && $query->deleted_at === null,
            'query' => $query === null ? null : [
                'id' => $query->id,
                'name' => $query->name,
                'is_archived' => $query->deleted_at !== null,
            ],
            'invited_by' => $share->sharedByUser === null ? null : [
                'id' => $share->sharedByUser->id,
                'name' => $share->sharedByUser->name,
            ],
        ];
    }

    private function kind(string $type): string
    {
        return in_array($type, [
            'query_share_invitation',
            'query_share_invitation_accepted',
            'query_share_invitation_declined',
            'query_change_request_created',
            'query_change_request_commented',
            'query_change_request_mentioned',
            'query_change_request_status_changed',
            'query_alert_triggered',
        ], true) ? $type : 'unknown';
    }

    private function displayStatus(QueryUserShare $share): string
    {
        if ($share->status === QueryUserShare::STATUS_PENDING && ! $share->isPending()) {
            return 'expired';
        }

        if (
            $share->status === QueryUserShare::STATUS_ACCEPTED
            && $share->expires_at !== null
            && $share->expires_at->isPast()
        ) {
            return 'expired';
        }

        return $share->status;
    }

    /**
     * @param  Collection<array-key, DatabaseNotification>  $notifications
     * @return list<int>
     */
    private function technicalIds(Collection $notifications, string $key): array
    {
        $ids = $notifications
            ->map(fn (DatabaseNotification $notification): mixed => $notification->data[$key] ?? null)
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [...$ids];
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $paginator
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toISOString() : null;
    }
}
