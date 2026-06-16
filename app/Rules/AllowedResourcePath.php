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
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var array<int, string> $prefixes */
        $prefixes = config('fusion.allowed_path_prefixes', []);

        if (! is_string($value) || ! Str::startsWith($value, $prefixes)) {
            $fail('Le chemin de ressource doit commencer par un préfixe Fusion autorisé ('.implode(', ', $prefixes).').');
        }
    }
}
