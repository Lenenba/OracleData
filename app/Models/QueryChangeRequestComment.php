<?php

namespace App\Models;

use Database\Factories\QueryChangeRequestCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $query_change_request_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryChangeRequest $changeRequest
 * @property-read User|null $author
 * @property-read Collection<int, User> $mentions
 */
#[Fillable(['query_change_request_id', 'user_id', 'body'])]
class QueryChangeRequestComment extends Model
{
    /** @use HasFactory<QueryChangeRequestCommentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(
            'Query change-request comments are immutable.',
        ));
        static::deleting(fn (): never => throw new LogicException(
            'Query change-request comments are immutable.',
        ));
    }

    /** @return BelongsTo<QueryChangeRequest, $this> */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(QueryChangeRequest::class, 'query_change_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'query_change_request_comment_mentions',
            'query_change_request_comment_id',
            'user_id',
        )->withTimestamps();
    }
}
