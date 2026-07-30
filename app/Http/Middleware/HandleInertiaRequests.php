<?php

namespace App\Http\Middleware;

use App\Models\OracleTenant;
use App\Models\QueryTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $canViewQueryTemplateGovernance = $user?->isSuperAdmin() ?? false;

        if (
            ! $canViewQueryTemplateGovernance
            && $user !== null
            && str_starts_with($request->path(), 'settings')
        ) {
            $canViewQueryTemplateGovernance = Gate::forUser($user)
                ->allows('viewAnyGovernance', QueryTemplate::class);
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'onboarding' => [
                    'completed' => $user?->hasCompletedOnboarding() ?? false,
                    'required' => $user !== null && ! $user->hasCompletedOnboarding(),
                ],
                'can_view_query_template_governance' => $canViewQueryTemplateGovernance,
            ],
            'notificationSummary' => [
                'unread_count' => $user?->unreadNotifications()->count() ?? 0,
            ],
            'oicTenants' => $user === null ? [] : OracleTenant::query()
                ->where('user_id', $user->id)
                ->where('type', 'oic')
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get(['id', 'key', 'label'])
                ->toArray(),
            'locale' => app()->getLocale(),
            'locales' => config('app.supported_locales'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
