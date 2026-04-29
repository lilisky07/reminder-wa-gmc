<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nps_ulasan', function (Blueprint $table) {
            $table->id();

            // Identitas pasien & kunjungan
            $table->string('no_rawat')->index();
            $table->string('no_sep')->nullable();
            $table->string('no_rkm_medis')->nullable();
            $table->string('nm_pasien')->nullable();
            $table->string('nm_poli')->nullable();        // unit pelayanan
            $table->string('jenis_rawat')->nullable();    // 'ranap' | 'rajal'
            $table->string('phone', 20)->nullable();

            // Status pengiriman — hanya boleh kirim sekali
            $table->boolean('sudah_kirim')->default(false);
            $table->timestamp('kirim_at')->nullable();

            // Hasil NPS
            $table->tinyInteger('skor')->nullable();      // 0–10
            $table->timestamp('skor_at')->nullable();
            $table->text('komentar')->nullable();
            $table->timestamp('komentar_at')->nullable();

            // 'detractor' (0-6) | 'passive' (7-8) | 'promoter' (9-10)
            $table->string('segmen', 20)->nullable();

            // Alert CS untuk detractor
            $table->boolean('sudah_direspons_cs')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nps_ulasan');
    }
};
