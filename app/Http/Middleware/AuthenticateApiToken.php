<?php

namespace App\Http\Middleware;

use App\Models\PersonalApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot 12B/12C — Authentifie les requêtes d'API via un token Bearer personnel.
 *
 * Le token est comparé en temps constant à son hash SHA-256 stocké.
 * Le scope requis est vérifié et le quota journalier consommé atomiquement.
 * En cas d'échec, une réponse JSON 401 ou 429 est retournée.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $plain = $this->extractBearer($request);

        if ($plain === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = PersonalApiToken::query()
            ->where('token_hash', PersonalApiToken::hashSecret($plain))
            ->where('is_active', true)
            ->first();

        if ($token === null || $token->isExpired()) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($requiredScopes as $scope) {
            if (! $token->hasScope($scope)) {
                return response()->json([
                    'message' => 'Forbidden. Required scope: '.$scope,
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Lot 12C — quota journalier
        if (! $token->consumeQuota()) {
            return response()->json([
                'message' => 'Daily API quota exceeded.',
                'daily_limit' => $token->daily_limit,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Bind the authenticated user and the resolved token to the request.
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plain = trim(substr($header, 7));

        return $plain !== '' ? $plain : null;
    }
}
