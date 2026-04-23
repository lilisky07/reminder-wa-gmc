<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cek surat kontrol baru setiap 5 menit → kirim notif WA
Schedule::command('reminder:surkon')->everyFiveMinutes();

// Reminder H-3 & H-1 setiap hari jam 16.00
Schedule::command('reminder:harian')->dailyAt('16:00');
