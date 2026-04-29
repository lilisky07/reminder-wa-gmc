<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WablasWebhookController;
use App\Http\Controllers\NpsLaporanController;

Route::get('/', function () {
    return view('welcome');
});

// Webhook dari Wablas — daftarkan URL ini di dashboard Wablas
// Contoh: https://yourdomain.com/webhook/wablas
Route::post('/webhook/wablas', [WablasWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('webhook.wablas');

// Endpoint untuk laporan NPS, dipanggil Google Apps Script
Route::get('/api/nps/laporan', [NpsLaporanController::class, 'index']);