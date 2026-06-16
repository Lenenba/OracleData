<?php

use App\Http\Controllers\QueryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('queries', [QueryController::class, 'index'])->name('queries.index');
    Route::get('queries/create', [QueryController::class, 'create'])->name('queries.create');
    Route::post('queries', [QueryController::class, 'store'])->name('queries.store');
});

require __DIR__.'/settings.php';
