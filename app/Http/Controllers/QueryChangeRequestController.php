<?php

namespace App\Http\Controllers;

use App\Enums\QueryChangeRequestStatus;
use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\QueryChangeRequestComment;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\QueryChangeRequestService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class QueryChangeRequestController extends Controller
{
    public function index(
        Request $request,
        Query $query,
        QueryChangeRequestService $service,
    ): JsonResponse|InertiaResponse {
        Gate::authorize('view', $query);
        /** @var array{status?: string|null, per_page?: int, page?: int} $validated */
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                'all',
                ...array_map(
                    static fn (QueryChangeRequestStatus $status): string => $status->value,
                    QueryChangeRequestStatus::cases(),
                ),
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $status = (string) ($validated['status'] ?? 'all');
        $perPage = (int) ($validated['per_page'] ?? 20);
        $changeRequests = $query->changeRequests()
            ->when($status !== 'all', fn (Builder $items): Builder => $items->where('status', $status))
            ->with(['requestedBy:id,name', 'statusChangedBy:id,name'])
            ->withCount('comments')
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
        $changeRequests->getCollection()->each(
            fn (QueryChangeRequest $changeRequest) => $changeRequest->setRelation('subjectQuery', $query),
        );

        $payload = [
            'query' => $this->queryPayload($query, $actor),
            'changeRequests' => [
                'data' => $changeRequests->getCollection()
                    ->map(fn (QueryChangeRequest $changeRequest): array => $this->summaryPayload($changeRequest, $actor))
                    ->values()
                    ->all(),
                'meta' => $this->paginationMeta($changeRequests),
            ],
            'statusFilter' => $status,
            'mentionCandidates' => $service->mentionCandidates($query, $actor)
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
        ];

        return $request->expectsJson()
            ? response()->json($payload)
            : Inertia::render('queries/change-requests/index', $payload);
    }

    public function store(
        Request $request,
        Query $query,
        QueryChangeRequestService $service,
        AuditRecorder $audit,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('requestChange', $query);
        /** @var array{title: string, message: string, mentioned_user_ids?: list<int>} $validated */
        $validated = $request->validate($this->commentRules([
            'title' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'message' => ['required', 'string', 'max:5000', 'regex:/\S/u'],
        ]));
        /** @var User $actor */
        $actor = $request->user();
        $changeRequest = $service->create(
            $actor,
            $query,
            trim($validated['title']),
            trim($validated['message']),
            $validated['mentioned_user_ids'] ?? [],
            $audit,
        );
        $changeRequest->setRelation('subjectQuery', $query);

        if ($request->expectsJson()) {
            return response()->json([
                'changeRequest' => $this->summaryPayload($changeRequest, $actor),
            ], Response::HTTP_CREATED);
        }

        return to_route('queries.change-requests.show', [$query, $changeRequest])
            ->with('status', 'query-change-request-created');
    }

    public function show(
        Request $request,
        Query $query,
        QueryChangeRequest $queryChangeRequest,
        QueryChangeRequestService $service,
    ): JsonResponse|InertiaResponse {
        $this->assertNested($query, $queryChangeRequest);
        $queryChangeRequest->setRelation('subjectQuery', $query);
        Gate::authorize('view', $queryChangeRequest);
        /** @var User $actor */
        $actor = $request->user();
        $comments = $queryChangeRequest->comments()
            ->with(['author:id,name', 'mentions:id,name'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();
        $queryChangeRequest
            ->loadMissing(['requestedBy:id,name', 'statusChangedBy:id,name'])
            ->loadCount('comments');

        $payload = [
            'query' => $this->queryPayload($query, $actor),
            'changeRequest' => $this->summaryPayload($queryChangeRequest, $actor),
            'comments' => $comments
                ->map(fn (QueryChangeRequestComment $comment): array => $this->commentPayload($comment))
                ->all(),
            'mentionCandidates' => $service->mentionCandidates($query, $actor)
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
        ];

        return $request->expectsJson()
            ? response()->json($payload)
            : Inertia::render('queries/change-requests/show', $payload);
    }

    public function update(
        Request $request,
        Query $query,
        QueryChangeRequest $queryChangeRequest,
        QueryChangeRequestService $service,
        AuditRecorder $audit,
    ): JsonResponse|RedirectResponse {
        $this->assertNested($query, $queryChangeRequest);
        /** @var array{status: string, response?: string|null} $validated */
        $validated = $request->validate([
            'status' => ['required', Rule::enum(QueryChangeRequestStatus::class)],
            'response' => ['nullable', 'string', 'max:5000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $result = $service->transition(
            $actor,
            $query,
            $queryChangeRequest,
            QueryChangeRequestStatus::from($validated['status']),
            isset($validated['response']) ? trim($validated['response']) : null,
            $audit,
        );
        $updated = $result['change_request']
            ->loadMissing(['requestedBy:id,name', 'statusChangedBy:id,name'])
            ->loadCount('comments');
        $updated->setRelation('subjectQuery', $query);

        if ($request->expectsJson()) {
            return response()->json([
                'changeRequest' => $this->summaryPayload($updated, $actor),
                'changed' => $result['changed'],
            ]);
        }

        return back()->with('status', $result['changed']
            ? 'query-change-request-updated'
            : 'query-change-request-unchanged');
    }

    /**
     * @param  array<string, list<string>>  $base
     * @return array<string, list<string>>
     */
    private function commentRules(array $base): array
    {
        return [
            ...$base,
            'mentioned_user_ids' => ['sometimes', 'array', 'max:10'],
            'mentioned_user_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, mixed> */
    private function queryPayload(Query $query, User $actor): array
    {
        return [
            'id' => $query->id,
            'name' => $query->name,
            'is_owner' => $query->user_id === $actor->id,
            'can_create_change_request' => $actor->can('requestChange', $query),
        ];
    }

    /** @return array<string, mixed> */
    private function summaryPayload(QueryChangeRequest $changeRequest, User $actor): array
    {
        $isOwner = $changeRequest->subjectQuery->user_id === $actor->id;
        $isRequester = $changeRequest->requested_by_user_id === $actor->id;

        return [
            'id' => $changeRequest->id,
            'title' => $changeRequest->title,
            'status' => $changeRequest->status->value,
            'requested_by' => $changeRequest->requestedBy === null ? null : [
                'id' => $changeRequest->requestedBy->id,
                'name' => $changeRequest->requestedBy->name,
            ],
            'status_changed_by' => $changeRequest->statusChangedBy === null ? null : [
                'id' => $changeRequest->statusChangedBy->id,
                'name' => $changeRequest->statusChangedBy->name,
            ],
            'status_changed_at' => $this->isoDate($changeRequest->status_changed_at),
            'comment_count' => (int) ($changeRequest->comments_count ?? 0),
            'created_at' => $this->isoDate($changeRequest->created_at),
            'updated_at' => $this->isoDate($changeRequest->updated_at),
            'can' => [
                'view' => $actor->can('view', $changeRequest),
                'comment' => $actor->can('comment', $changeRequest),
                'update_status' => $isOwner && in_array($changeRequest->status, [
                    QueryChangeRequestStatus::PENDING,
                    QueryChangeRequestStatus::ACCEPTED,
                ], true),
                'cancel' => ($isRequester && $changeRequest->status === QueryChangeRequestStatus::PENDING)
                    || ($isOwner && $changeRequest->status === QueryChangeRequestStatus::ACCEPTED),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function commentPayload(QueryChangeRequestComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'author' => $comment->author === null ? null : [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
            ],
            'mentions' => $comment->mentions
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
            'created_at' => $this->isoDate($comment->created_at),
        ];
    }

    private function assertNested(Query $query, QueryChangeRequest $changeRequest): void
    {
        abort_unless($changeRequest->query_id === $query->id, Response::HTTP_NOT_FOUND);
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
