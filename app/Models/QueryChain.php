<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Liaison dynamique entre deux requêtes par un identifiant commun.
 *
 * @property int $id
 * @property int $primary_query_id
 * @property int $secondary_query_id
 * @property int $user_id
 * @property string $extraction_field
 * @property string $injection_param
 * @property string $injection_operator 'equals'|'in'
 * @property string|null $label
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Query $primaryQuery
 * @property-read Query $secondaryQuery
 * @property-read User $user
 */
#[Fillable([
    'primary_query_id',
    'secondary_query_id',
    'user_id',
    'extraction_field',
    'injection_param',
    'injection_operator',
    'label',
    'position',
])]
class QueryChain extends Model
{
    public const int MAX_PER_QUERY = 5;

    public const array ALLOWED_OPERATORS = ['equals', 'in'];

    /**
     * @return BelongsTo<Query, $this>
     */
    public function primaryQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'primary_query_id');
    }

    /**
     * @return BelongsTo<Query, $this>
     */
    public function secondaryQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'secondary_query_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
