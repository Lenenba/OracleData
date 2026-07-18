<?php

namespace App\Services;

use App\Models\QueryTemplate;
use InvalidArgumentException;
use LogicException;

/**
 * Applies the audit disclosure policy declared for each template parameter.
 */
class QueryTemplateAuditSanitizer
{
    /** @var list<string> */
    private const array MODES = ['clear', 'masked', 'hmac', 'omit'];

    /**
     * Only parameters declared by the immutable template are considered.
     * Missing policies default to omit, so a new parameter cannot leak by
     * accident. A raw value is returned only for an explicit `clear` policy.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, bool|float|int|string|null>
     */
    public function sanitize(QueryTemplate $template, array $values): array
    {
        $sanitized = [];

        foreach ($template->parameter_definitions ?? [] as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if ($key === '' || ! array_key_exists($key, $values)) {
                continue;
            }

            $mode = $this->modeFor($definition);

            if ($mode === 'omit') {
                continue;
            }

            $value = $this->scalarValue($values[$key]);
            $sanitized[$key] = match ($mode) {
                'clear' => $value,
                'masked' => '[masked]',
                'hmac' => $this->fingerprint($template, $key, $value),
            };
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $definition */
    private function modeFor(array $definition): string
    {
        $policy = $definition['audit'] ?? $definition['audit_mode'] ?? null;

        if (is_array($policy)) {
            $policy = $policy['mode'] ?? null;
        }

        if ($policy === null || $policy === '') {
            $policy = config('audit.query_template_parameters.default_mode', 'omit');
        }

        $mode = mb_strtolower(trim((string) $policy));

        return in_array($mode, self::MODES, true) ? $mode : 'omit';
    }

    private function fingerprint(QueryTemplate $template, string $parameter, bool|float|int|string|null $value): string
    {
        $version = trim((string) config('audit.query_template_parameters.hmac.version', 'v1'));
        $key = (string) config('audit.query_template_parameters.hmac.key', '');

        if ($version === '' || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version) !== 1) {
            throw new LogicException('La version HMAC des paramètres d’audit est invalide.');
        }

        if ($key === '') {
            throw new LogicException('La clé HMAC dédiée aux paramètres d’audit est absente.');
        }

        $payload = implode("\0", [
            'oracle-data/query-template-parameter',
            $version,
            (string) $template->slug,
            $parameter,
            $this->canonicalValue($value),
        ]);

        return 'hmac-sha256:'.$version.':'.hash_hmac('sha256', $payload, $key);
    }

    private function canonicalValue(bool|float|int|string|null $value): string
    {
        return match (true) {
            $value === null => 'null:',
            is_bool($value) => 'bool:'.($value ? '1' : '0'),
            is_int($value) => 'int:'.$value,
            is_float($value) => 'number:'.$this->canonicalFloat($value),
            default => 'string:'.$value,
        };
    }

    private function canonicalFloat(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('La valeur numérique à auditer est invalide.');
        }

        $formatted = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    private function scalarValue(mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Seules les valeurs scalaires peuvent être inscrites dans l’audit.');
    }
}
