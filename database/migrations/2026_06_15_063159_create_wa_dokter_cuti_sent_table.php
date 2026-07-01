<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_dokter_cuti_sent', function (Blueprint $table) {
            $table->id();
            $table->string('no_sep')->index();
            $table->string('no_tlp', 20)->nullable();
            $table->string('nm_pasien');
            $table->string('nm_dokter');
            $table->date('tgl_kontrol');
            $table->timestamps();

            $table->unique(['no_sep']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_dokter_cuti_sent');
    }
};