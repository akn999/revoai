<?php

use App\Http\Controllers\Embedded\StatusController;
use App\Http\Controllers\Webhooks\SallaWebhookController;
use App\Http\Middleware\AuthenticateSallaEmbedded;
use App\Http\Middleware\EnsureMerchantCanUseApp;
use App\Http\Middleware\VerifySallaWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/salla', SallaWebhookController::class)
    ->middleware(VerifySallaWebhook::class)
    ->name('webhooks.salla');

Route::middleware([AuthenticateSallaEmbedded::class, EnsureMerchantCanUseApp::class])
    ->get('/embedded/status', StatusController::class)
    ->name('api.embedded.status');
