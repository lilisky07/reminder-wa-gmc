<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_surkon_sent', function (Blueprint $table) {
            $table->id();
            $table->string('no_sep')->unique()->index();
            $table->string('no_tlp')->nullable();
            $table->string('nm_pasien')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_surkon_sent');
    }
};
