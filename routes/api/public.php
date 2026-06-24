<?php

use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('telegram')->group(function () {

    // ── Webhook (called by Telegram) ──────────────────────────────────────────
    Route::post('/webhook', [TelegramWebhookController::class, 'webhook'])
        ->name('telegram.webhook');

    // ── Utility (local/dev only — protect in production) ─────────────────────
    Route::get('/set-webhook',  [TelegramWebhookController::class, 'setWebhook'])
        ->name('telegram.set-webhook');

    Route::get('/webhook-info', [TelegramWebhookController::class, 'webhookInfo'])
        ->name('telegram.webhook-info');

    Route::get('/test',         [TelegramWebhookController::class, 'testMessage'])
        ->name('telegram.test');
});