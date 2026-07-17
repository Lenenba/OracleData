<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('app.supported_locales')))],
        ]);

        $request->user()?->forceFill(['locale' => $validated['locale']])->save();

        return back()->withCookie(cookie()->forever('locale', $validated['locale']));
    }
}
