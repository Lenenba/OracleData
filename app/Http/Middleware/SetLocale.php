<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_values(array_map(
            fn (int|string $locale): string => (string) $locale,
            array_keys((array) config('app.supported_locales')),
        ));
        $locale = $request->user()?->locale;

        if (! in_array($locale, $supported, true)) {
            $locale = $request->cookie('locale');
        }

        if (! in_array($locale, $supported, true)) {
            $locale = $request->getPreferredLanguage($supported);
        }

        App::setLocale(in_array($locale, $supported, true) ? $locale : config('app.locale'));

        return $next($request);
    }
}
