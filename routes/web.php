<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OracleTenantController;
use App\Http\Controllers\QueryChangeRequestCommentController;
use App\Http\Controllers\QueryChangeRequestController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\QueryGroupShareController;
use App\Http\Controllers\QueryPreferenceController;
use App\Http\Controllers\QueryShareController;
use App\Http\Controllers\QueryShareInvitationController;
use App\Http\Controllers\QueryTemplateController;
use App\Http\Controllers\SavedQueryViewController;
use App\Http\Middleware\EnsureOnboardingCompleted;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::patch('locale', LocaleController::class)->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('onboarding/connection', [OnboardingController::class, 'show'])
        ->name('onboarding.connection');
    Route::post('onboarding/connection', [OnboardingController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('onboarding.connection.store');

    Route::post('oracle-tenants/test', [OracleTenantController::class, 'testConnection'])
        ->middleware('throttle:10,1')
        ->name('oracle-tenants.test');

    Route::middleware(EnsureOnboardingCompleted::class)->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::patch('notifications/read-all', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])
            ->name('notifications.read');
        Route::post('query-share-invitations/{queryUserShare}/accept', [QueryShareInvitationController::class, 'accept'])
            ->middleware('throttle:20,1,query-share-invitation-response')
            ->name('query-share-invitations.accept');
        Route::post('query-share-invitations/{queryUserShare}/decline', [QueryShareInvitationController::class, 'decline'])
            ->middleware('throttle:20,1,query-share-invitation-response')
            ->name('query-share-invitations.decline');

        Route::get('oracle-tenants', [OracleTenantController::class, 'index'])->name('oracle-tenants.index');
        Route::post('oracle-tenants', [OracleTenantController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('oracle-tenants.store');
        Route::get('oracle-tenants/{tenant}/edit', [OracleTenantController::class, 'edit'])->name('oracle-tenants.edit');
        Route::put('oracle-tenants/{tenant}', [OracleTenantController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('oracle-tenants.update');
        Route::delete('oracle-tenants/{tenant}', [OracleTenantController::class, 'destroy'])->name('oracle-tenants.destroy');

        Route::get('groups', [GroupController::class, 'index'])->name('groups.index');
        Route::post('groups', [GroupController::class, 'store'])->name('groups.store');
        Route::patch('groups/{group}', [GroupController::class, 'update'])->name('groups.update');
        Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
        Route::post('groups/{group}/members', [GroupMemberController::class, 'store'])->name('groups.members.store');
        Route::patch('groups/{group}/members/{user}', [GroupMemberController::class, 'update'])->name('groups.members.update');
        Route::delete('groups/{group}/members/{user}', [GroupMemberController::class, 'destroy'])->name('groups.members.destroy');

        Route::get('queries', [QueryController::class, 'index'])->name('queries.index');
        Route::get('queries/shared', [QueryController::class, 'shared'])->name('queries.shared');
        Route::get('queries/create', [QueryController::class, 'create'])->name('queries.create');
        Route::post('queries', [QueryController::class, 'store'])->name('queries.store');

        Route::get('query-templates', [QueryTemplateController::class, 'index'])->name('query-templates.index');
        Route::get('query-templates/{queryTemplate}', [QueryTemplateController::class, 'show'])->name('query-templates.show');
        Route::post('query-templates/{queryTemplate}/clone', [QueryTemplateController::class, 'duplicate'])->name('query-templates.clone');
        Route::post('query-templates/{queryTemplate}/preview', [QueryTemplateController::class, 'preview'])
            ->middleware('throttle:60,1,query-template-preview')
            ->name('query-templates.preview');
        Route::post('query-templates/{queryTemplate}/run', [QueryTemplateController::class, 'run'])
            ->middleware('throttle:15,1,query-template-run')
            ->name('query-templates.run');

        Route::get('queries/{query}/edit', [QueryController::class, 'edit'])->name('queries.edit');
        Route::put('queries/{query}', [QueryController::class, 'update'])->name('queries.update');
        Route::get('queries/{query}/sharing', [QueryShareController::class, 'index'])->name('queries.shares.index');
        Route::get('queries/{query}/change-requests', [QueryChangeRequestController::class, 'index'])
            ->name('queries.change-requests.index');
        Route::post('queries/{query}/change-requests', [QueryChangeRequestController::class, 'store'])
            ->middleware('throttle:10,1,query-change-request')
            ->name('queries.change-requests.store');
        Route::get('queries/{query}/change-requests/{queryChangeRequest}', [QueryChangeRequestController::class, 'show'])
            ->name('queries.change-requests.show');
        Route::patch('queries/{query}/change-requests/{queryChangeRequest}', [QueryChangeRequestController::class, 'update'])
            ->middleware('throttle:20,1,query-change-request-status')
            ->name('queries.change-requests.update');
        Route::post('queries/{query}/change-requests/{queryChangeRequest}/comments', [QueryChangeRequestCommentController::class, 'store'])
            ->middleware('throttle:30,1,query-change-request-comment')
            ->name('queries.change-requests.comments.store');
        Route::post('queries/{query}/shares', [QueryShareController::class, 'store'])->name('queries.shares.store');
        Route::post('queries/{query}/share-invitations', [QueryShareInvitationController::class, 'store'])
            ->middleware('throttle:30,1,query-share-invitation')
            ->name('queries.invitations.store');
        Route::patch('queries/{query}/shares/{queryUserShare}', [QueryShareController::class, 'update'])->name('queries.shares.update');
        Route::delete('queries/{query}/shares/{queryUserShare}', [QueryShareController::class, 'destroy'])->name('queries.shares.destroy');
        Route::post('queries/{query}/group-shares', [QueryGroupShareController::class, 'store'])->name('queries.group-shares.store');
        Route::patch('queries/{query}/group-shares/{queryGroupShare}', [QueryGroupShareController::class, 'update'])->name('queries.group-shares.update');
        Route::delete('queries/{query}/group-shares/{queryGroupShare}', [QueryGroupShareController::class, 'destroy'])->name('queries.group-shares.destroy');
        Route::patch('queries/{query}/access-level', [QueryShareController::class, 'updateAccessLevel'])->name('queries.access-level');
        Route::patch('queries/{query}/preference', [QueryPreferenceController::class, 'update'])->name('queries.preference');
        Route::post('queries/{query}/clone', [QueryController::class, 'duplicate'])->name('queries.clone');
        Route::delete('queries/{query}', [QueryController::class, 'destroy'])->name('queries.destroy');

        Route::post('saved-query-views', [SavedQueryViewController::class, 'store'])->name('saved-query-views.store');
        Route::put('saved-query-views/{savedQueryView}', [SavedQueryViewController::class, 'update'])->name('saved-query-views.update');
        Route::delete('saved-query-views/{savedQueryView}', [SavedQueryViewController::class, 'destroy'])->name('saved-query-views.destroy');

        // Routes coûteuses (appels Claude + Oracle) : limitées à 15 req/min par utilisateur.
        Route::middleware('throttle:15,1')->group(function () {
            Route::post('queries/preview', [QueryController::class, 'preview'])->name('queries.preview');
            Route::post('queries/{query}/run', [QueryController::class, 'run'])->name('queries.run');
        });

        // Aperçu direct sans LLM (query builder live) : GET Oracle bornés, quota plus large.
        Route::post('queries/direct-preview', [QueryController::class, 'directPreview'])
            ->middleware('throttle:60,1')
            ->name('queries.direct-preview');

        // Découverte des champs d'une ressource sur le tenant du lecteur (sonde
        // limit=1, cachée). Compteur préfixé : sans lui, la signature throttle
        // par utilisateur est partagée avec direct-preview et les previews
        // rapides du builder feraient rejeter les sondes en 429.
        Route::post('queries/resource-fields', [QueryController::class, 'resourceFields'])
            ->middleware('throttle:60,1,resource-fields')
            ->name('queries.resource-fields');

        Route::get('queries/{query}', [QueryController::class, 'show'])->name('queries.show');
    });
});

require __DIR__.'/settings.php';
