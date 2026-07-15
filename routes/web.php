<?php

use App\Http\Controllers\OracleTenantController;
use App\Http\Controllers\QueryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('oracle-tenants', [OracleTenantController::class, 'index'])->name('oracle-tenants.index');
    Route::post('oracle-tenants', [OracleTenantController::class, 'store'])->name('oracle-tenants.store');

    Route::get('queries', [QueryController::class, 'index'])->name('queries.index');
    Route::get('queries/create', [QueryController::class, 'create'])->name('queries.create');
    Route::post('queries', [QueryController::class, 'store'])->name('queries.store');
    Route::post('queries/preview', [QueryController::class, 'preview'])->name('queries.preview');
    Route::get('queries/{query}', [QueryController::class, 'show'])->name('queries.show');
    Route::post('queries/{query}/run', [QueryController::class, 'run'])->name('queries.run');
});

require __DIR__.'/settings.php';
