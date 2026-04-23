<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReminderSurkon extends Command
{
    protected $signature   = 'reminder:surkon';
    protected $description = 'Kirim notif WA saat surat kontrol BPJS dibuat (cek tiap 5 menit)';

    const WABLAS_TOKEN = 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP';
    const WABLAS_SECRET = '4vWr3WU7';
    const WABLAS_URL   = 'https://jogja.wablas.com/api/send-message';

    public function handle()
    {
        $data = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->select(
                'sk.no_sep',
                'sk.nm_poli_bpjs as nm_poli',
                'sk.nm_dokter_bpjs',
                'sk.tgl_rencana',
                'p.nm_pasien',
                'p.no_tlp'
            )
            ->whereDate('sk.tgl_surat', now())
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->whereNotIn('sk.no_sep', DB::table('wa_surkon_sent')->pluck('no_sep'))
            ->get();

        if ($data->isEmpty()) {
            echo "Tidak ada surat kontrol baru.\n";
            return;
        }

        foreach ($data as $item) {
            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam  = $this->ambilJam($item->nm_dokter_bpjs, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $pesan = "Terima kasih telah memilih RSU GMC 🙏\n\n"
                . "Berikut rencana kontrol kak {$item->nm_pasien}:\n\n"
                . "🏥 Poli    : {$item->nm_poli}\n"
                . "👨‍⚕️ Dokter  : {$item->nm_dokter_bpjs}\n"
                . "📅 Tanggal : {$hari}, {$item->tgl_rencana}\n"
                . "⏰ Jam     : {$jam}\n\n"
                . "Sampai bertemu di RSU GMC dan hati-hati di jalan 👋\n\n"
                . "_Catatan: Untuk peserta BPJS, perubahan jadwal kontrol hanya bisa dilakukan maksimal H-1._";

            $res = Http::withHeaders([
                'Authorization' => self::WABLAS_TOKEN,
                'secret-key'    => self::WABLAS_SECRET,
            ])->post(self::WABLAS_URL, [
                'phone'   => $no,
                'message' => $pesan,
            ]);

            if ($res->successful()) {
                DB::table('wa_surkon_sent')->insert([
                    'no_sep'     => $item->no_sep,
                    'no_tlp'     => $no,
                    'nm_pasien'  => $item->nm_pasien,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                echo "✔ Terkirim ke: $no - {$item->nm_pasien}\n";
            } else {
                echo "❌ Gagal ke: $no\n";
                echo $res->body() . "\n";
            }

            sleep(2);
        }

        echo "✅ Selesai!\n";
    }

    private function ambilJam($nm_dokter_bpjs, $tanggal)
    {
        $hari = $this->getHariIndo($tanggal);

        $jadwal = DB::table('jadwal as j')
            ->join('dokter as d', function($join) use ($nm_dokter_bpjs) {
                $join->on('j.kd_dokter', '=', 'd.kd_dokter')
                     ->where(function($q) use ($nm_dokter_bpjs) {
                         $q->whereRaw('LOWER(d.nm_dokter) LIKE LOWER(?)', ["%{$nm_dokter_bpjs}%"])
                           ->orWhereRaw('LOWER(?) LIKE LOWER(CONCAT("%", d.nm_dokter, "%"))', [$nm_dokter_bpjs]);
                     });
            })
            ->where('j.hari_kerja', $hari)
            ->select('j.jam_mulai', 'j.jam_selesai')
            ->first();

        if ($jadwal && $jadwal->jam_mulai && $jadwal->jam_selesai) {
            return $jadwal->jam_mulai . ' - ' . $jadwal->jam_selesai;
        }

        return 'Sesuai jadwal dokter';
    }

    private function getHariIndo($tanggal)
    {
        return match (date('l', strtotime($tanggal))) {
            'Monday'    => 'SENIN',
            'Tuesday'   => 'SELASA',
            'Wednesday' => 'RABU',
            'Thursday'  => 'KAMIS',
            'Friday'    => 'JUMAT',
            'Saturday'  => 'SABTU',
            'Sunday'    => 'MINGGU',
            default     => '-',
        };
    }

    private function formatNomor($no)
    {
        if (!$no) return null;
        $no = preg_replace('/[^0-9]/', '', $no);
        if (substr($no, 0, 2) == '08') {
            $no = '62' . substr($no, 1);
        } elseif (substr($no, 0, 2) != '62') {
            return null;
        }
        return $no;
    }
}
