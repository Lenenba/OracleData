<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Champs Oracle d'une ressource (ou d'un enfant expand) découverts sur un
 * tenant, mis en cache durablement pour éviter de resonder Oracle à chaque
 * interaction. Le schéma dépend du tenant (modules, flexfields custom), d'où
 * le rattachement à `oracle_tenant_id` plutôt qu'un catalogue global.
 *
 * @property int $id
 * @property int $oracle_tenant_id
 * @property string $resource_key
 * @property string $child
 * @property list<string> $fields
 * @property Carbon $discovered_at
 * @property-read OracleTenant $oracleTenant
 */
#[Fillable([
    'oracle_tenant_id',
    'resource_key',
    'child',
    'fields',
    'discovered_at',
])]
class OracleResourceField extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'discovered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OracleTenant, $this>
     */
    public function oracleTenant(): BelongsTo
    {
        return $this->belongsTo(OracleTenant::class);
    }
}
