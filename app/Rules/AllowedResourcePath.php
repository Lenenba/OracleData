<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Le chemin REST doit commencer par un préfixe Fusion autorisé
 * (liste blanche dans config/fusion.php) pour interdire les URLs arbitraires.
 */
class AllowedResourcePath implements ValidationRule
{
    public const int MAX_LENGTH = 2048;

    /**
     * Determine whether a relative path is a safe Oracle Fusion REST resource.
     *
     * This method is shared by form requests and collection importers so no
     * alternate entry point can bypass the same allowlist and traversal checks.
     */
    public static function isAllowed(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        /** @var array<int, string> $prefixes */
        $prefixes = array_values(array_filter(
            config('fusion.allowed_path_prefixes', []),
            fn (mixed $prefix): bool => is_string($prefix) && str_starts_with($prefix, '/'),
        ));

        if ($prefixes === [] || ! Str::startsWith($value, $prefixes)) {
            return false;
        }

        if (
            preg_match('/[\\x00-\\x1F\\x7F\\\\?#]/u', $value) === 1
            || str_contains($value, '{{')
            || str_contains($value, '}}')
            || str_contains($value, '//')
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1
            || preg_match('/%(?:2e|2f|5c|25)/i', $value) === 1
        ) {
            return false;
        }

        if (preg_match('#^/(?:hcm|fscm)RestApi/resources/(?:latest|\\d+(?:\\.\\d+){3})/(?:[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)(?:/[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)*$#', $value) !== 1) {
            return false;
        }

        foreach (explode('/', trim($value, '/')) as $segment) {
            $decoded = rawurldecode($segment);

            if ($decoded === '' || $decoded === '.' || $decoded === '..' || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var array<int, string> $prefixes */
        $prefixes = config('fusion.allowed_path_prefixes', []);

        if (! self::isAllowed($value)) {
            $fail('Le chemin doit être une ressource REST Fusion relative et autorisée ('.implode(', ', $prefixes).').');
        }
    }
}
