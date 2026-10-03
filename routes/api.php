<?php

use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Platform\SessionController;
use App\Http\Controllers\Webhooks\SallaWebhookController;
use App\Http\Middleware\AuthenticateEmbeddedSession;
use App\Http\Middleware\VerifySallaWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/salla', SallaWebhookController::class)
    ->middleware(VerifySallaWebhook::class)
    ->name('webhooks.salla');

Route::prefix('app')->name('app.')->group(function () {
    Route::post('/session', SessionController::class)->middleware('throttle:30,1')->name('session');

    Route::middleware([AuthenticateEmbeddedSession::class, 'throttle:app-api'])->group(function () {
        Route::get('/overview', OverviewController::class)->name('overview');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/quote', [ProductController::class, 'quote'])->name('products.quote');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::post('/products/{product}/generate', [ProductController::class, 'generate'])->name('products.generate');
        Route::post('/products/{product}/resync', [ProductController::class, 'resync'])->name('products.resync');
        Route::get('/products/{product}/versions', [ProductController::class, 'versions'])->name('products.versions');
        Route::post('/versions/{version}/revert', [ProductController::class, 'revert'])->name('versions.revert');
        Route::get('/generations', [ProductController::class, 'generations'])->name('generations.index');
        Route::get('/generations/{generation}', [ProductController::class, 'showGeneration'])->name('generations.show');
        Route::post('/generations/{generation}/approve', [ProductController::class, 'approve'])->name('generations.approve');
        Route::post('/generations/{generation}/reject', [ProductController::class, 'reject'])->name('generations.reject');
        Route::post('/bulk/estimate', [ProductController::class, 'estimateBulk'])->name('bulk.estimate');
        Route::post('/bulk', [ProductController::class, 'startBulk'])->name('bulk.start');
        Route::get('/bulk/{job}', [ProductController::class, 'showBulk'])->name('bulk.show');
        Route::post('/bulk/{job}/cancel', [ProductController::class, 'cancelBulk'])->name('bulk.cancel');

        Route::get('/images/quote', [ImageController::class, 'quote'])->name('images.quote');
        Route::post('/products/{product}/images/{image}/edit', [ImageController::class, 'edit'])->name('images.edit');
        Route::get('/image-generations/{generation}', [ImageController::class, 'show'])->name('images.show');

        Route::get('/media', [MediaController::class, 'index'])->name('media.index');
        Route::post('/media', [MediaController::class, 'upload'])->name('media.upload');
        Route::get('/media/folders', [MediaController::class, 'folders'])->name('media.folders');
        Route::post('/media/folders', [MediaController::class, 'createFolder'])->name('media.folders.create');
        Route::post('/media/{image}/approve', [MediaController::class, 'approve'])->name('media.approve');
        Route::post('/media/{image}/discard', [MediaController::class, 'discard'])->name('media.discard');
        Route::post('/media/{image}/attach', [MediaController::class, 'attach'])->name('media.attach');
        Route::put('/media/{image}/folder', [MediaController::class, 'move'])->name('media.move');
        Route::put('/media/{image}/tags', [MediaController::class, 'tag'])->name('media.tag');
        Route::delete('/media/{image}', [MediaController::class, 'destroy'])->name('media.destroy');

        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general');
        Route::put('/settings/prompts/{key}', [SettingsController::class, 'savePrompt'])->name('settings.prompt');
        Route::put('/settings/context', [SettingsController::class, 'saveContext'])->name('settings.context');
        Route::post('/settings/presets', [SettingsController::class, 'createPreset'])->name('settings.presets.create');
        Route::put('/settings/presets/{preset}', [SettingsController::class, 'updatePreset'])->name('settings.presets.update');
        Route::delete('/settings/presets/{preset}', [SettingsController::class, 'deletePreset'])->name('settings.presets.delete');
        Route::get('/drafts/{formKey}', [SettingsController::class, 'showDraft'])->name('drafts.show');
        Route::put('/drafts/{formKey}', [SettingsController::class, 'saveDraft'])->name('drafts.save');
        Route::delete('/drafts/{formKey}', [SettingsController::class, 'discardDraft'])->name('drafts.discard');

        Route::get('/billing/packs', [BillingController::class, 'packs'])->name('billing.packs');
        Route::post('/billing/intents', [BillingController::class, 'createIntent'])->name('billing.intents');
        Route::post('/billing/intents/{uuid}/result', [BillingController::class, 'intentResult'])->name('billing.result');
        Route::get('/billing/history', [BillingController::class, 'history'])->name('billing.history');
    });
});
