<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;

/**
 * Point d'entrée unique du journal d'audit.
 *
 * Refuse par construction tout contexte contenant des identifiants ou des
 * secrets : l'invariant « audit sans secrets » est appliqué ici plutôt que
 * délégué à la discipline de chaque appelant.
 */
class AuditRecorder
{
    /**
     * Clés interdites dans le contexte, quelle que soit leur profondeur.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_CONTEXT_KEYS = [
        'password',
        'secret',
        'identifier',
        'username',
        'token',
        'authorization',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(?User $actor, string $action, ?Model $subject = null, array $context = []): AuditEvent
    {
        $this->assertSafeContext($context);

        return AuditEvent::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context === [] ? null : $context,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function assertSafeContext(array $context): void
    {
        foreach ($context as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::FORBIDDEN_CONTEXT_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Le contexte d'audit ne doit pas contenir la clé [{$key}].",
                );
            }

            if (is_array($value)) {
                $this->assertSafeContext($value);
            }
        }
    }
}
