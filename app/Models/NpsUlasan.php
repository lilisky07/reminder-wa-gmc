<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpsUlasan extends Model
{
    protected $table = 'nps_ulasan';

    protected $fillable = [
        'no_rawat',
        'no_sep',
        'no_rkm_medis',
        'nm_pasien',
        'nm_poli',
        'jenis_rawat',
        'phone',
        'sudah_kirim',
        'kirim_at',
        'skor',
        'skor_at',
        'komentar',
        'komentar_at',
        'segmen',
        'sudah_direspons_cs',
    ];

    protected $casts = [
        'sudah_kirim'        => 'boolean',
        'sudah_direspons_cs' => 'boolean',
        'kirim_at'           => 'datetime',
        'skor_at'            => 'datetime',
        'komentar_at'        => 'datetime',
    ];

    /**
     * Hitung segmen berdasarkan skor NPS.
     */
    public static function hitungSegmen(int $skor): string
    {
        return match (true) {
            $skor <= 6  => 'detractor',
            $skor <= 8  => 'passive',
            default     => 'promoter',
        };
    }

    /**
     * Cek apakah no_rawat sudah pernah dikirim NPS.
     */
    public static function sudahDikirim(string $noRawat): bool
    {
        return self::where('no_rawat', $noRawat)
            ->where('sudah_kirim', true)
            ->exists();
    }
}
