<?php

namespace App\Models;

use Database\Factories\QueryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read OracleTenant|null $oracleTenant
 */
#[Fillable(['name', 'description', 'resource_path', 'tenant_key', 'oracle_tenant_id', 'mode', 'parameters', 'visibility'])]
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
}
