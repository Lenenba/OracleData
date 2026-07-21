<?php

use App\Http\Controllers\Api\QueryApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Lot 12B/12C
|--------------------------------------------------------------------------
|
| Ces routes sont protégées par le middleware `api.token` qui valide le
| token Bearer personnel, vérifie les scopes et consume le quota journalier.
|
| Préfixe automatique /api (configuré dans bootstrap/app.php).
|
*/

// ── Queries (lecture) ────────────────────────────────────────────────────────
Route::middleware('api.token:read:queries')->group(function () {
    Route::get('/v1/queries', [QueryApiController::class, 'index'])
        ->name('api.queries.index');
    Route::get('/v1/queries/{query}', [QueryApiController::class, 'show'])
        ->name('api.queries.show');
});

// ── Queries (exécution) ──────────────────────────────────────────────────────
Route::middleware(['api.token:read:queries,run:queries', 'throttle:15,1,api-run'])->group(function () {
    Route::post('/v1/queries/{query}/run', [QueryApiController::class, 'run'])
        ->name('api.queries.run');
});
