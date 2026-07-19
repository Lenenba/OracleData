<?php

use App\Http\Controllers\Settings\CategoryController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\QueryTemplateCertificationController;
use App\Http\Controllers\Settings\QueryTemplateGovernanceController;
use App\Http\Controllers\Settings\QueryTemplateVersionController;
use App\Http\Controllers\Settings\QueryTemplateVersionComparisonController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TagController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Administration des catégories de la bibliothèque (super-admin uniquement).
    Route::get('settings/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('settings/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('settings/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('settings/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Cycle éditorial versionné des modèles officiels (super-admin uniquement).
    Route::get('settings/query-templates', [QueryTemplateGovernanceController::class, 'index'])
        ->name('query-template-governance.index');
    Route::get('settings/query-templates/{queryTemplate}', [QueryTemplateGovernanceController::class, 'show'])
        ->name('query-template-governance.show');
    Route::post('settings/query-templates/{queryTemplate}/versions', [QueryTemplateVersionController::class, 'store'])
        ->name('query-template-governance.versions.store');
    Route::get('settings/query-templates/{queryTemplate}/versions/compare', QueryTemplateVersionComparisonController::class)
        ->name('query-template-governance.versions.compare');
    Route::patch('settings/query-templates/{queryTemplate}/versions/{queryTemplateVersion}', [QueryTemplateVersionController::class, 'update'])
        ->name('query-template-governance.versions.update');
    Route::post('settings/query-templates/{queryTemplate}/versions/{queryTemplateVersion}/submit', [QueryTemplateVersionController::class, 'submit'])
        ->name('query-template-governance.versions.submit');
    Route::post('settings/query-templates/{queryTemplate}/versions/{queryTemplateVersion}/publish', [QueryTemplateVersionController::class, 'publish'])
        ->name('query-template-governance.versions.publish');
    Route::post('settings/query-templates/{queryTemplate}/versions/{queryTemplateVersion}/restore', [QueryTemplateVersionController::class, 'restore'])
        ->name('query-template-governance.versions.restore');
    Route::post('settings/query-templates/{queryTemplate}/certifications', [QueryTemplateCertificationController::class, 'store'])
        ->name('query-template-governance.certifications.store');
    Route::post('settings/query-templates/{queryTemplate}/certifications/{queryTemplateCertification}/revoke', [QueryTemplateCertificationController::class, 'revoke'])
        ->name('query-template-governance.certifications.revoke');
    Route::post('settings/query-templates/{queryTemplate}/archive', [QueryTemplateGovernanceController::class, 'archive'])
        ->name('query-template-governance.archive');

    Route::post('settings/tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('settings/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('settings/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
