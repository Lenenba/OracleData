<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Restrict server-side connection tests to explicitly allowed HTTPS hosts.
 */
class SafeOracleBaseUrl implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail(__('Cette URL Oracle est invalide.'));

            return;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($scheme !== 'https' || $host === '') {
            $fail(__('La connexion Oracle doit utiliser une URL HTTPS.'));

            return;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            $fail(__("L'URL Oracle ne doit contenir ni identifiants, ni paramètres, ni fragment."));

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $publicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($publicIp === false) {
                $fail(__('Les adresses réseau privées ou réservées ne sont pas autorisées.'));

                return;
            }
        }

        $allowedSuffixes = array_values(array_filter(array_map(
            fn (mixed $suffix): string => strtolower(trim((string) $suffix, " .\t\n\r\0\x0B")),
            (array) config('fusion.allowed_host_suffixes', []),
        )));

        if ($allowedSuffixes === []) {
            $fail(__('Aucun domaine Oracle autorisé n’est configuré.'));

            return;
        }

        $isAllowed = collect($allowedSuffixes)->contains(
            fn (string $suffix): bool => $host === $suffix || str_ends_with($host, '.'.$suffix),
        );

        if (! $isAllowed) {
            $fail(__('Cet hôte Oracle ne fait pas partie des domaines autorisés.'));
        }
    }
}
