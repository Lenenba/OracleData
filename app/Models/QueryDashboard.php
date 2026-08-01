<?php

namespace App\Models;

use Database\Factories\QueryDashboardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, QueryDashboardWidget> $widgets
 */
#[Fillable(['user_id', 'name', 'description'])]
class QueryDashboard extends Model
{
    /** @use HasFactory<QueryDashboardFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<QueryDashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(QueryDashboardWidget::class, 'dashboard_id')->orderBy('position');
    }
}
