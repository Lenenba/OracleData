<?php

namespace App\Models;

use Database\Factories\OracleTenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $label
 * @property string $base_url
 * @property string $username
 * @property string $password
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'label', 'base_url', 'username', 'password', 'is_default', 'is_active'])]
class OracleTenant extends Model
{
    /** @use HasFactory<OracleTenantFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
