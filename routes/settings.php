<?php

use App\Http\Controllers\QueryAlertController;
use App\Http\Controllers\QueryScheduleController;
use App\Http\Controllers\Settings\CategoryController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\QueryTemplateCertificationController;
use App\Http\Controllers\Settings\QueryTemplateDelegationController;
use App\Http\Controllers\Settings\QueryTemplateGovernanceController;
use App\Http\Controllers\Settings\QueryTemplateQualityController;
use App\Http\Controllers\Settings\QueryTemplateReferenceDatasetController;
use App\Http\Controllers\Settings\QueryTemplateVersionComparisonController;
use App\Http\Controllers\Settings\QueryTemplateVersionController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SemanticCatalogController;
use App\Http\Controllers\Settings\SemanticFieldController;
use App\Http\Controllers\Settings\SemanticGlossaryController;
use App\Http\Controllers\Settings\SemanticRelationController;
use App\Http\Controllers\Settings\TagController;
use App\Http\Controllers\WebhookEndpointController;
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

    Route::get('settings/semantic-catalog', [SemanticCatalogController::class, 'index'])
        ->name('semantic-catalog.index');
    Route::get('settings/semantic-catalog/{semanticResource:resource_key}', [SemanticCatalogController::class, 'show'])
        ->name('semantic-catalog.show');
    Route::patch('settings/semantic-catalog/{semanticResource:resource_key}', [SemanticCatalogController::class, 'update'])
        ->name('semantic-catalog.update');
    Route::patch('settings/semantic-catalog/{semanticResource:resource_key}/fields/{semanticField}', [SemanticFieldController::class, 'update'])
        ->name('semantic-catalog.fields.update');
    Route::post('settings/semantic-catalog/{semanticResource:resource_key}/relations', [SemanticRelationController::class, 'store'])
        ->name('semantic-catalog.relations.store');
    Route::patch('settings/semantic-catalog/{semanticResource:resource_key}/relations/{semanticRelation}', [SemanticRelationController::class, 'update'])
        ->name('semantic-catalog.relations.update');
    Route::post('settings/semantic-glossary', [SemanticGlossaryController::class, 'store'])
        ->name('semantic-glossary.store');
    Route::patch('settings/semantic-glossary/{semanticGlossaryTerm}', [SemanticGlossaryController::class, 'update'])
        ->name('semantic-glossary.update');

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
    Route::post('settings/query-templates/{queryTemplate}/versions/{queryTemplateVersion}/quality-runs', [QueryTemplateQualityController::class, 'store'])
        ->name('query-template-governance.versions.quality-runs.store');
    Route::post('settings/query-templates/{queryTemplate}/versions/{queryTemplateVersion}/reference-datasets', [QueryTemplateReferenceDatasetController::class, 'store'])
        ->name('query-template-governance.versions.reference-datasets.store');
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
    Route::patch('settings/query-templates/{queryTemplate}/delegations/{user}', [QueryTemplateDelegationController::class, 'update'])
        ->name('query-template-governance.delegations.update');
    Route::patch('settings/query-templates/{queryTemplate}/technical-owner', [QueryTemplateGovernanceController::class, 'updateTechnicalOwner'])
        ->name('query-template-governance.technical-owner.update');

    Route::post('settings/tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('settings/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('settings/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

    // Automatisation : planifications récurrentes des requêtes de l'utilisateur.
    Route::get('settings/automation', [QueryScheduleController::class, 'index'])->name('automation.index');
    Route::post('settings/schedules', [QueryScheduleController::class, 'store'])->name('schedules.store');
    Route::patch('settings/schedules/{querySchedule}', [QueryScheduleController::class, 'update'])->name('schedules.update');
    Route::delete('settings/schedules/{querySchedule}', [QueryScheduleController::class, 'destroy'])->name('schedules.destroy');
    Route::post('settings/schedules/{querySchedule}/alerts', [QueryAlertController::class, 'store'])->name('alerts.store');
    Route::patch('settings/alerts/{queryAlert}', [QueryAlertController::class, 'update'])->name('alerts.update');
    Route::delete('settings/alerts/{queryAlert}', [QueryAlertController::class, 'destroy'])->name('alerts.destroy');
    Route::post('settings/webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
    Route::patch('settings/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
    Route::delete('settings/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
