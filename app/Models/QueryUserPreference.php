<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Préférences personnelles d'un utilisateur pour une requête accessible.
 *
 * @property int $id
 * @property int $user_id
 * @property int $query_id
 * @property bool $is_favorite
 * @property bool $is_pinned
 * @property Carbon|null $pinned_at
 * @property-read User $user
 * @property-read Query $preferredQuery
 */
#[Fillable([
    'user_id',
    'query_id',
    'is_favorite',
    'is_pinned',
    'pinned_at',
])]
class QueryUserPreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'is_pinned' => 'boolean',
            'pinned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `query()` étant réservé par Eloquent, la relation porte un nom explicite.
     *
     * @return BelongsTo<Query, $this>
     */
    public function preferredQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }
}
