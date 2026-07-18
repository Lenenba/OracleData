<?php

namespace App\Models;

use Database\Factories\QueryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property string|null $resource_path
 * @property string|null $tenant_key
 * @property int|null $oracle_tenant_id
 * @property string $mode
 * @property array<string, mixed>|null $parameters
 * @property string $visibility
 * @property int|null $category_id
 * @property int $execution_count
 * @property int $successful_execution_count
 * @property Carbon|null $last_executed_at
 * @property Carbon|null $last_successful_execution_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read OracleTenant|null $oracleTenant
 * @property-read Collection<int, QueryExecution> $executions
 * @property-read Category|null $category
 * @property-read Collection<int, Tag> $tags
 */
#[Fillable(['name', 'description', 'resource_path', 'tenant_key', 'oracle_tenant_id', 'mode', 'parameters', 'visibility', 'category_id'])]
class Query extends Model
{
    /** @use HasFactory<QueryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'last_executed_at' => 'datetime',
            'last_successful_execution_at' => 'datetime',
        ];
    }

    /**
     * The user that owns the query.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The owner's preferred Oracle environment for this query.
     *
     * Shared readers must always resolve their own execution environment.
     *
     * @return BelongsTo<OracleTenant, $this>
     */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }

    /**
     * Execution history, kept even after the query is deleted (nullable FK).
     *
     * @return HasMany<QueryExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(QueryExecution::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
