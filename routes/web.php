<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OracleTenantController;
use App\Http\Controllers\QueryController;
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

        Route::get('oracle-tenants', [OracleTenantController::class, 'index'])->name('oracle-tenants.index');
        Route::post('oracle-tenants', [OracleTenantController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('oracle-tenants.store');
        Route::get('oracle-tenants/{tenant}/edit', [OracleTenantController::class, 'edit'])->name('oracle-tenants.edit');
        Route::put('oracle-tenants/{tenant}', [OracleTenantController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('oracle-tenants.update');
        Route::delete('oracle-tenants/{tenant}', [OracleTenantController::class, 'destroy'])->name('oracle-tenants.destroy');

        Route::get('queries', [QueryController::class, 'index'])->name('queries.index');
        Route::get('queries/shared', [QueryController::class, 'shared'])->name('queries.shared');
        Route::get('queries/create', [QueryController::class, 'create'])->name('queries.create');
        Route::post('queries', [QueryController::class, 'store'])->name('queries.store');
        Route::get('queries/{query}/edit', [QueryController::class, 'edit'])->name('queries.edit');
        Route::put('queries/{query}', [QueryController::class, 'update'])->name('queries.update');
        Route::patch('queries/{query}/visibility', [QueryController::class, 'updateVisibility'])->name('queries.visibility');
        Route::post('queries/{query}/clone', [QueryController::class, 'duplicate'])->name('queries.clone');
        Route::delete('queries/{query}', [QueryController::class, 'destroy'])->name('queries.destroy');

        // Routes coûteuses (appels Claude + Oracle) : limitées à 15 req/min par utilisateur.
        Route::middleware('throttle:15,1')->group(function () {
            Route::post('queries/preview', [QueryController::class, 'preview'])->name('queries.preview');
            Route::post('queries/{query}/run', [QueryController::class, 'run'])->name('queries.run');
        });

        // Aperçu direct sans LLM (query builder live) : GET Oracle bornés, quota plus large.
        Route::post('queries/direct-preview', [QueryController::class, 'directPreview'])
            ->middleware('throttle:60,1')
            ->name('queries.direct-preview');

        Route::get('queries/{query}', [QueryController::class, 'show'])->name('queries.show');
    });
});

require __DIR__.'/settings.php';
