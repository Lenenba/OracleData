<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingCompleted
{
    /**
     * Keep product features behind the historical onboarding milestone.
     * Connection availability is checked separately at execution time, while
     * onboarding and account-security routes remain reachable for recovery.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasCompletedOnboarding()) {
            return to_route('onboarding.connection');
        }

        return $next($request);
    }
}
