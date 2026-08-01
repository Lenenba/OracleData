<?php

namespace App\Models;

use Database\Factories\QueryDashboardWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dashboard_id
 * @property int $query_id
 * @property string $widget_type kpi | table | chart
 * @property string|null $title
 * @property int $position
 * @property array<string, mixed>|null $widget_options
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueryDashboard $dashboard
 * @property-read Query $sourceQuery
 */
#[Fillable(['dashboard_id', 'query_id', 'widget_type', 'title', 'position', 'widget_options'])]
class QueryDashboardWidget extends Model
{
    /** @use HasFactory<QueryDashboardWidgetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'widget_options' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<QueryDashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(QueryDashboard::class, 'dashboard_id');
    }

    /**
     * @return BelongsTo<Query, $this>
     */
    public function sourceQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }
}
