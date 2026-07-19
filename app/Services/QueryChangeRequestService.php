<?php

namespace App\Services;

use App\Enums\QueryAccessLevel;
use App\Enums\QueryChangeRequestStatus;
use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\QueryChangeRequestComment;
use App\Models\QueryGroupShare;
use App\Models\QueryUserShare;
use App\Models\User;
use App\Notifications\QueryChangeRequestActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class QueryChangeRequestService
{
    private const int MAX_MENTION_CANDIDATES = 50;

    /**
     * @param  list<int>  $mentionedUserIds
     */
    public function create(
        User $actor,
        Query $query,
        string $title,
        string $message,
        array $mentionedUserIds,
        AuditRecorder $audit,
    ): QueryChangeRequest {
        return DB::transaction(function () use (
            $actor,
            $audit,
            $message,
            $mentionedUserIds,
            $query,
            $title,
        ): QueryChangeRequest {
            $lockedQuery = Query::query()->lockForUpdate()->findOrFail($query->id);
            Gate::forUser($actor)->authorize('requestChange', $lockedQuery);
            $mentionedUsers = $this->resolveMentionedUsers($lockedQuery, $actor, $mentionedUserIds);

            $changeRequest = QueryChangeRequest::query()->create([
                'query_id' => $lockedQuery->id,
                'requested_by_user_id' => $actor->id,
                'title' => $title,
                'status' => QueryChangeRequestStatus::PENDING,
                'status_changed_by_user_id' => null,
                'status_changed_at' => null,
            ]);
            $comment = $this->persistComment($changeRequest, $actor, $message, $mentionedUsers);

            $audit->record($actor, 'query.change_request_created', $changeRequest, [
                'query_id' => $lockedQuery->id,
                'requester_user_id' => $actor->id,
                'comment_id' => $comment->id,
                'mentioned_user_ids' => $mentionedUsers->modelKeys(),
                'status' => QueryChangeRequestStatus::PENDING->value,
            ]);
            $this->notifyActivity(
                QueryChangeRequestActivityNotification::CREATED,
                $changeRequest,
                $actor,
                $comment,
                [$lockedQuery->user_id],
                $mentionedUsers->modelKeys(),
            );

            return $changeRequest
                ->load(['requestedBy:id,name', 'statusChangedBy:id,name'])
                ->loadCount('comments');
        });
    }

    /**
     * @param  list<int>  $mentionedUserIds
     */
    public function comment(
        User $actor,
        Query $query,
        QueryChangeRequest $changeRequest,
        string $body,
        array $mentionedUserIds,
        AuditRecorder $audit,
    ): QueryChangeRequestComment {
        return DB::transaction(function () use (
            $actor,
            $audit,
            $body,
            $changeRequest,
            $mentionedUserIds,
            $query,
        ): QueryChangeRequestComment {
            [$lockedQuery, $lockedRequest] = $this->lockNested($query, $changeRequest);
            Gate::forUser($actor)->authorize('comment', $lockedRequest);
            $mentionedUsers = $this->resolveMentionedUsers($lockedQuery, $actor, $mentionedUserIds);
            $comment = $this->persistComment($lockedRequest, $actor, $body, $mentionedUsers);

            $audit->record($actor, 'query.change_request_commented', $lockedRequest, [
                'query_id' => $lockedQuery->id,
                'comment_id' => $comment->id,
                'author_user_id' => $actor->id,
                'mentioned_user_ids' => $mentionedUsers->modelKeys(),
                'status' => $lockedRequest->status->value,
            ]);
            $this->notifyActivity(
                QueryChangeRequestActivityNotification::COMMENTED,
                $lockedRequest,
                $actor,
                $comment,
                [$lockedQuery->user_id, $lockedRequest->requested_by_user_id],
                $mentionedUsers->modelKeys(),
            );

            return $comment->load(['author:id,name', 'mentions:id,name']);
        });
    }

    /**
     * @return array{change_request: QueryChangeRequest, changed: bool}
     */
    public function transition(
        User $actor,
        Query $query,
        QueryChangeRequest $changeRequest,
        QueryChangeRequestStatus $target,
        ?string $response,
        AuditRecorder $audit,
    ): array {
        return DB::transaction(function () use (
            $actor,
            $audit,
            $changeRequest,
            $query,
            $response,
            $target,
        ): array {
            [$lockedQuery, $lockedRequest] = $this->lockNested($query, $changeRequest);
            Gate::forUser($actor)->authorize('transition', $lockedRequest);

            if ($lockedRequest->status === $target) {
                $canReplay = $actor->id === $lockedQuery->user_id
                    || (
                        $actor->id === $lockedRequest->requested_by_user_id
                        && $target === QueryChangeRequestStatus::CANCELLED
                    );
                abort_unless(
                    $canReplay,
                    Response::HTTP_CONFLICT,
                    __('Cette transition de demande de modification n’est pas autorisée.'),
                );

                return ['change_request' => $lockedRequest, 'changed' => false];
            }

            $this->authorizeTransition($actor, $lockedQuery, $lockedRequest, $target);
            $before = $lockedRequest->status;
            $comment = null;

            if ($response !== null && trim($response) !== '') {
                $comment = $this->persistComment($lockedRequest, $actor, trim($response), new Collection);
            }

            $lockedRequest->transitionTo($target, $actor);
            $audit->record($actor, 'query.change_request_status_changed', $lockedRequest, [
                'query_id' => $lockedQuery->id,
                'status_changed_by_user_id' => $actor->id,
                'before_status' => $before->value,
                'after_status' => $target->value,
                'response_comment_id' => $comment?->id,
            ]);
            $this->notifyActivity(
                QueryChangeRequestActivityNotification::STATUS_CHANGED,
                $lockedRequest,
                $actor,
                $comment,
                [$lockedQuery->user_id, $lockedRequest->requested_by_user_id],
                [],
            );

            return ['change_request' => $lockedRequest->refresh(), 'changed' => true];
        });
    }

    /** @return Collection<int, User> */
    public function mentionCandidates(Query $query, User $actor): Collection
    {
        $users = User::query()
            ->select(['id', 'name'])
            ->whereKeyNot($actor->id);

        if ($query->access_level === QueryAccessLevel::PRIVATE) {
            $users->whereKey($query->user_id);
        } elseif ($query->access_level === QueryAccessLevel::RESTRICTED) {
            $directRecipients = QueryUserShare::query()
                ->select('user_id')
                ->where('query_id', $query->id)
                ->active();
            $users->where(function (Builder $readers) use ($directRecipients, $query): void {
                $readers
                    ->whereKey($query->user_id)
                    ->orWhereIn('id', $directRecipients)
                    ->orWhereHas('groups.queryShares', function (Builder $shares) use ($query): void {
                        /** @var Builder<QueryGroupShare> $shares */
                        $shares
                            ->where('query_id', $query->id)
                            ->active();
                    });
            });
        }

        return $users
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MAX_MENTION_CANDIDATES)
            ->get();
    }

    /**
     * Close unfinished collaboration before a query becomes unreachable. The
     * caller already owns the query lock and transaction used for archiving.
     */
    public function cancelForArchivedQuery(
        User $actor,
        Query $query,
        AuditRecorder $audit,
    ): int {
        $changeRequests = QueryChangeRequest::query()
            ->where('query_id', $query->id)
            ->whereIn('status', [
                QueryChangeRequestStatus::PENDING->value,
                QueryChangeRequestStatus::ACCEPTED->value,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($changeRequests as $changeRequest) {
            $before = $changeRequest->status;
            $changeRequest->transitionTo(QueryChangeRequestStatus::CANCELLED, $actor);
            $audit->record($actor, 'query.change_request_status_changed', $changeRequest, [
                'query_id' => $query->id,
                'status_changed_by_user_id' => $actor->id,
                'before_status' => $before->value,
                'after_status' => QueryChangeRequestStatus::CANCELLED->value,
                'reason' => 'query_archived',
            ]);
            $this->notifyActivity(
                QueryChangeRequestActivityNotification::STATUS_CHANGED,
                $changeRequest,
                $actor,
                null,
                [$query->user_id, $changeRequest->requested_by_user_id],
                [],
            );
        }

        return $changeRequests->count();
    }

    /**
     * @return array{Query, QueryChangeRequest}
     */
    private function lockNested(Query $query, QueryChangeRequest $changeRequest): array
    {
        $lockedQuery = Query::query()->lockForUpdate()->findOrFail($query->id);
        $lockedRequest = QueryChangeRequest::query()
            ->whereKey($changeRequest->id)
            ->where('query_id', $lockedQuery->id)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedRequest->setRelation('subjectQuery', $lockedQuery);

        return [$lockedQuery, $lockedRequest];
    }

    private function authorizeTransition(
        User $actor,
        Query $query,
        QueryChangeRequest $changeRequest,
        QueryChangeRequestStatus $target,
    ): void {
        $isOwner = $actor->id === $query->user_id;
        $isRequester = $actor->id === $changeRequest->requested_by_user_id;
        $allowed = $isOwner
            ? match ($changeRequest->status) {
                QueryChangeRequestStatus::PENDING => in_array($target, [
                    QueryChangeRequestStatus::ACCEPTED,
                    QueryChangeRequestStatus::REJECTED,
                ], true),
                QueryChangeRequestStatus::ACCEPTED => in_array($target, [
                    QueryChangeRequestStatus::COMPLETED,
                    QueryChangeRequestStatus::CANCELLED,
                ], true),
                default => false,
            }
            : $isRequester
                && $changeRequest->status === QueryChangeRequestStatus::PENDING
                && $target === QueryChangeRequestStatus::CANCELLED;

        abort_unless($allowed, Response::HTTP_CONFLICT, __('Cette transition de demande de modification n’est pas autorisée.'));
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function resolveMentionedUsers(Query $query, User $actor, array $userIds): Collection
    {
        $ids = collect($userIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->contains($actor->id)) {
            throw ValidationException::withMessages([
                'mentioned_user_ids' => __('Vous ne pouvez pas vous mentionner vous-même.'),
            ]);
        }

        $users = User::query()
            ->select(['id', 'name'])
            ->whereKey($ids->all())
            ->get();

        if ($users->count() !== $ids->count() || $users->contains(
            fn (User $user): bool => Gate::forUser($user)->denies('view', $query),
        )) {
            throw ValidationException::withMessages([
                'mentioned_user_ids' => __('Chaque personne mentionnée doit encore pouvoir consulter la requête.'),
            ]);
        }

        return $users;
    }

    /**
     * @param  Collection<int, User>  $mentionedUsers
     */
    private function persistComment(
        QueryChangeRequest $changeRequest,
        User $actor,
        string $body,
        Collection $mentionedUsers,
    ): QueryChangeRequestComment {
        $comment = $changeRequest->comments()->create([
            'user_id' => $actor->id,
            'body' => $body,
        ]);
        $comment->mentions()->sync($mentionedUsers->modelKeys());

        return $comment;
    }

    /**
     * @param  list<int|null>  $baseRecipientIds
     * @param  list<int>  $mentionedRecipientIds
     */
    private function notifyActivity(
        string $event,
        QueryChangeRequest $changeRequest,
        User $actor,
        ?QueryChangeRequestComment $comment,
        array $baseRecipientIds,
        array $mentionedRecipientIds,
    ): void {
        $mentionedIds = collect($mentionedRecipientIds)
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $actor->id)
            ->unique()
            ->values();
        $baseIds = collect($baseRecipientIds)
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $actor->id || $mentionedIds->contains($id))
            ->unique()
            ->values();
        $recipients = User::query()
            ->whereKey($baseIds->concat($mentionedIds)->unique()->all())
            ->get()
            ->keyBy(fn (User $user): int => $user->id);

        foreach ($baseIds as $recipientId) {
            $recipients->get($recipientId)?->notify(new QueryChangeRequestActivityNotification(
                $event,
                $changeRequest->id,
                $changeRequest->query_id,
                $actor->id,
                $comment?->id,
            ));
        }

        foreach ($mentionedIds as $recipientId) {
            $recipients->get($recipientId)?->notify(new QueryChangeRequestActivityNotification(
                QueryChangeRequestActivityNotification::MENTIONED,
                $changeRequest->id,
                $changeRequest->query_id,
                $actor->id,
                $comment?->id,
            ));
        }
    }
}
