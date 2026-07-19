<?php

namespace App\Http\Controllers;

use App\Models\Query;
use App\Models\QueryChangeRequest;
use App\Models\QueryChangeRequestComment;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\QueryChangeRequestService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QueryChangeRequestCommentController extends Controller
{
    public function store(
        Request $request,
        Query $query,
        QueryChangeRequest $queryChangeRequest,
        QueryChangeRequestService $service,
        AuditRecorder $audit,
    ): JsonResponse|RedirectResponse {
        abort_unless($queryChangeRequest->query_id === $query->id, Response::HTTP_NOT_FOUND);
        /** @var array{body: string, mentioned_user_ids?: list<int>} $validated */
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000', 'regex:/\S/u'],
            'mentioned_user_ids' => ['sometimes', 'array', 'max:10'],
            'mentioned_user_ids.*' => ['integer', 'distinct'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $comment = $service->comment(
            $actor,
            $query,
            $queryChangeRequest,
            trim($validated['body']),
            $validated['mentioned_user_ids'] ?? [],
            $audit,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'comment' => $this->commentPayload($comment),
            ], Response::HTTP_CREATED);
        }

        return back()->with('status', 'query-change-request-commented');
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
            'created_at' => $comment->created_at instanceof CarbonInterface
                ? $comment->created_at->toISOString()
                : null,
        ];
    }
}
