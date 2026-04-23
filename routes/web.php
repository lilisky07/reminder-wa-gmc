<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WablasWebhookController;

Route::get('/', function () {
    return view('welcome');
});

// Webhook dari Wablas — daftarkan URL ini di dashboard Wablas
// Contoh: https://yourdomain.com/webhook/wablas
Route::post('/webhook/wablas', [WablasWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('webhook.wablas');
