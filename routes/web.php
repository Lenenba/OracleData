<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OracleTenantController;
use App\Http\Controllers\QueryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('oracle-tenants', [OracleTenantController::class, 'index'])->name('oracle-tenants.index');
    Route::post('oracle-tenants', [OracleTenantController::class, 'store'])->name('oracle-tenants.store');
    Route::post('oracle-tenants/test', [OracleTenantController::class, 'testConnection'])->name('oracle-tenants.test');
    Route::get('oracle-tenants/{tenant}/edit', [OracleTenantController::class, 'edit'])->name('oracle-tenants.edit');
    Route::put('oracle-tenants/{tenant}', [OracleTenantController::class, 'update'])->name('oracle-tenants.update');
    Route::delete('oracle-tenants/{tenant}', [OracleTenantController::class, 'destroy'])->name('oracle-tenants.destroy');

    Route::get('queries', [QueryController::class, 'index'])->name('queries.index');
    Route::get('queries/create', [QueryController::class, 'create'])->name('queries.create');
    Route::post('queries', [QueryController::class, 'store'])->name('queries.store');
    Route::patch('queries/{query}/visibility', [QueryController::class, 'updateVisibility'])->name('queries.visibility');
    Route::delete('queries/{query}', [QueryController::class, 'destroy'])->name('queries.destroy');

    // Routes coûteuses (appels Claude + Oracle) : limitées à 15 req/min par utilisateur.
    Route::middleware('throttle:15,1')->group(function () {
        Route::post('queries/preview', [QueryController::class, 'preview'])->name('queries.preview');
        Route::post('queries/{query}/run', [QueryController::class, 'run'])->name('queries.run');
    });

    Route::get('queries/{query}', [QueryController::class, 'show'])->name('queries.show');
});

require __DIR__.'/settings.php';
