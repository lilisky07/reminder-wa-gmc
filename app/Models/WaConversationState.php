<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaConversationState extends Model
{
    protected $fillable = [
        'phone',
        'state',
        'nm_pasien',
        'nm_poli',
        'nm_dokter',
        'tgl_rencana',
        'kd_dokter',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Ambil state aktif (belum kadaluarsa) berdasarkan nomor HP.
     */
    public static function aktif(string $phone): ?self
    {
        return self::where('phone', $phone)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }
}
