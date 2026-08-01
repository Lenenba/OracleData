<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PersonalApiToken;
use App\Services\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lot 12B/12C — Gestion des tokens d'API personnels depuis les paramètres.
 *
 * Le secret brut n'est renvoyé qu'une seule fois à la création puis effacé ;
 * il n'est jamais inclus dans les payloads Inertia ultérieurs.
 */
class ApiTokenController extends Controller
{
    private const array ALLOWED_SCOPES = [
        PersonalApiToken::SCOPE_READ_QUERIES,
        PersonalApiToken::SCOPE_RUN_QUERIES,
    ];

    public function index(Request $request): Response
    {
        $tokens = $request->user()
            ->personalApiTokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalApiToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'scopes' => $t->scopes,
                'is_active' => $t->is_active,
                'daily_limit' => $t->daily_limit,
                'requests_today' => $t->requests_today,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('settings/api-tokens', [
            'tokens' => $tokens,
            'allowed_scopes' => self::ALLOWED_SCOPES,
            'plain_token' => session('api_token_plain'),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(self::ALLOWED_SCOPES)],
            'daily_limit' => ['nullable', 'integer', 'min:10', 'max:100000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $plain = PersonalApiToken::generateSecret();

        $token = $request->user()->personalApiTokens()->create([
            'name' => $data['name'],
            'token_hash' => PersonalApiToken::hashSecret($plain),
            'scopes' => array_values(array_unique($data['scopes'])),
            'is_active' => true,
            'daily_limit' => $data['daily_limit'] ?? 1000,
            'expires_at' => isset($data['expires_at']) ? $data['expires_at'] : null,
        ]);

        $audit->record($request->user(), 'api_token.created', $request->user(), [
            'token_id' => $token->id,
            'name' => $token->name,
            'scopes' => $token->scopes,
        ]);

        // Flash the plain-text secret once — never stored after this point.
        // Uses Laravel's native session flash so the value is consumed on the
        // very next request (the redirect to api-tokens.index) and discarded.
        session()->flash('api_token_plain', $plain);

        return to_route('api-tokens.index');
    }

    public function update(Request $request, PersonalApiToken $personalApiToken, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($personalApiToken->user_id === (int) $request->user()->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(self::ALLOWED_SCOPES)],
            'is_active' => ['nullable', 'boolean'],
            'daily_limit' => ['nullable', 'integer', 'min:10', 'max:100000'],
        ]);

        $personalApiToken->update([
            'name' => $data['name'],
            'scopes' => array_values(array_unique($data['scopes'])),
            'is_active' => $data['is_active'] ?? $personalApiToken->is_active,
            'daily_limit' => $data['daily_limit'] ?? $personalApiToken->daily_limit,
        ]);

        $audit->record($request->user(), 'api_token.updated', $request->user(), [
            'token_id' => $personalApiToken->id,
        ]);

        return to_route('api-tokens.index');
    }

    public function destroy(Request $request, PersonalApiToken $personalApiToken, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($personalApiToken->user_id === (int) $request->user()->id, 404);

        $audit->record($request->user(), 'api_token.revoked', $request->user(), [
            'token_id' => $personalApiToken->id,
            'name' => $personalApiToken->name,
        ]);

        $personalApiToken->delete();

        return to_route('api-tokens.index');
    }
}
