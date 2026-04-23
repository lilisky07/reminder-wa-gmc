<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();

            // awaiting_reschedule_confirmation → pasien baru terima reminder, belum balas
            // awaiting_new_date                → pasien pilih "ubah jadwal", tunggu tanggal baru
            $table->string('state', 50)->default('awaiting_reschedule_confirmation');

            // Simpan data kontrol agar bisa dikirim ulang ke petugas
            $table->string('nm_pasien')->nullable();
            $table->string('nm_poli')->nullable();
            $table->string('nm_dokter')->nullable();
            $table->string('tgl_rencana')->nullable();   // format: YYYY-MM-DD
            $table->string('kd_dokter')->nullable();     // jam praktek

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_conversation_states');
    }
};
