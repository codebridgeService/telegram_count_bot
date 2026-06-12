<?php

use App\Http\Controllers\Api\TelegramPaymentWebhookController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TelegramWebhookController;

Route::prefix('telegram')->group(function () {

    Route::post('/webhook', [TelegramWebhookController::class, 'webhook']);

    Route::post('/payment-webhook', [TelegramPaymentWebhookController::class, 'webhook']);

    Route::get('/set-webhook', [TelegramPaymentWebhookController::class, 'setWebhook']);
    Route::get('/webhook-info', [TelegramPaymentWebhookController::class, 'webhookInfo']);
    
    if (app()->environment('local')) {
        Route::get('/test-connect', [TelegramWebhookController::class, 'testConnect']);
    }
});