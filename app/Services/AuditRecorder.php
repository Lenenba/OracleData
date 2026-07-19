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
        'api_key',
        'private_key',
        'client_secret',
        'access_token',
        'refresh_token',
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
            $segmentedKey = preg_replace(
                '/(?<=[a-z0-9])(?=[A-Z])/',
                '_',
                (string) $key,
            ) ?? (string) $key;
            $normalizedKey = mb_strtolower($segmentedKey);
            $containsForbiddenSegment = preg_match(
                '/(?:^|[_.-])(password|secret|token|authorization|username|identifier|api[_.-]key|private[_.-]key)(?:$|[_.-])/',
                $normalizedKey,
            ) === 1;

            if (in_array($normalizedKey, self::FORBIDDEN_CONTEXT_KEYS, true) || $containsForbiddenSegment) {
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
