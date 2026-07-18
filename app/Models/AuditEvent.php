<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Journal d'audit immuable des mutations et exécutions, sans secrets.
 *
 * Chaque ligne est écrite une seule fois par AuditRecorder ; toute mise à jour
 * ou suppression via Eloquent est refusée. La purge de rétention future devra
 * passer par le query builder.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $context
 * @property string|null $request_id
 * @property Carbon $created_at
 * @property-read User|null $user
 */
#[Fillable([
    'user_id',
    'action',
    'subject_type',
    'subject_id',
    'context',
    'request_id',
])]
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException("Les événements d'audit sont immuables.");
        });

        static::deleting(function (): never {
            throw new LogicException("Les événements d'audit ne peuvent pas être supprimés.");
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
