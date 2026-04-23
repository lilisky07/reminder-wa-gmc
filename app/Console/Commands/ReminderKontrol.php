<?php

namespace App\Console\Commands;

use App\Models\WaConversationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReminderKontrol extends Command
{
    protected $signature = 'reminder:kontrol';
    protected $description = 'Kirim reminder kontrol pasien via WhatsApp';

    const WABLAS_TOKEN     = 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP';
    const WABLAS_SECRET    = '4vWr3WU7';
    const WABLAS_URL       = 'https://jogja.wablas.com/api/send-message';
    const WABLAS_LIST_URL  = 'https://jogja.wablas.com/api/v2/send-list';

    public function handle()
    {
        // =========================
        // 1. NOTIF SAAT SURAT DIBUAT (HARI INI)
        // =========================
        $dataAwal = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->join('poliklinik as pl', 'rp.kd_poli', '=', 'pl.kd_poli')
            ->select('p.nm_pasien', 'p.no_tlp', 'sk.tgl_rencana', 'sk.nm_dokter_bpjs as nm_dokter', 'rp.kd_dokter', 'pl.nm_poli')
            ->whereDate('sk.tgl_surat', now())
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->get();

        foreach ($dataAwal as $item) {
            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam  = $this->ambilJam($item->kd_dokter, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $pesan = "Terima kasih telah memilih RSU GMC 🙏\n\n"
                . "Berikut rencana kontrol kak {$item->nm_pasien}:\n\n"
                . "🏥 Poli    : {$item->nm_poli}\n"
                . "👨‍⚕️ Dokter  : {$item->nm_dokter}\n"
                . "📅 Tanggal : {$hari}, {$item->tgl_rencana}\n"
                . "⏰ Jam     : {$jam}\n\n"
                . "Sampai bertemu di RSU GMC dan hati-hati di jalan 👋\n\n"
                . "_Catatan: Untuk peserta BPJS, perubahan jadwal kontrol hanya bisa dilakukan maksimal H-1._";

            $this->kirimWa($no, $pesan, $item->nm_pasien);
        }

        // =========================
        // 2. REMINDER H-3 (pakai List Message / button)
        // =========================
        $dataH3 = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->join('poliklinik as pl', 'rp.kd_poli', '=', 'pl.kd_poli')
            ->select('p.nm_pasien', 'p.no_tlp', 'sk.tgl_rencana', 'sk.nm_dokter_bpjs as nm_dokter', 'rp.kd_dokter', 'pl.nm_poli')
            ->whereDate('sk.tgl_rencana', now()->addDays(3))
            ->whereDate('sk.tgl_surat', '>=', now()->subDays(7))
            ->get();

        foreach ($dataH3 as $item) {
            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam  = $this->ambilJam($item->kd_dokter, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $this->kirimListMessage($no, [
                'title'       => '🔔 Pengingat H-3',
                'description' => "Halo kak {$item->nm_pasien}, kembali mengingatkan jadwal kontrol kakak:\n\n"
                    . "🏥 Poli    : {$item->nm_poli}\n"
                    . "👨‍⚕️ Dokter  : {$item->nm_dokter}\n"
                    . "📅 Tanggal : {$hari}, {$item->tgl_rencana}\n"
                    . "⏰ Jam     : {$jam}\n\n"
                    . "Apakah kakak ingin melakukan perubahan jadwal?",
                'buttonText'  => 'Pilih',
                'lists'       => [
                    ['title' => 'Ubah jadwal', 'description' => 'Saya ingin mengubah jadwal kontrol'],
                    ['title' => 'Tetap',       'description' => 'Saya tetap dengan jadwal yang ada'],
                ],
                'footer' => 'RSU GMC',
            ], $item->nm_pasien);

            WaConversationState::updateOrCreate(
                ['phone' => $no],
                [
                    'state'       => 'awaiting_reschedule_confirmation',
                    'nm_pasien'   => $item->nm_pasien,
                    'nm_poli'     => $item->nm_poli,
                    'nm_dokter'   => $item->nm_dokter,
                    'tgl_rencana' => $item->tgl_rencana,
                    'kd_dokter'   => $item->kd_dokter,
                    'expires_at'  => now()->addHours(48),
                ]
            );

            sleep(2);
        }

        // =========================
        // 3. REMINDER H-1 (pakai List Message / button)
        // =========================
        $dataH1 = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->join('poliklinik as pl', 'rp.kd_poli', '=', 'pl.kd_poli')
            ->select('p.nm_pasien', 'p.no_tlp', 'sk.tgl_rencana', 'sk.nm_dokter_bpjs as nm_dokter', 'rp.kd_dokter', 'pl.nm_poli')
            ->whereDate('sk.tgl_rencana', now()->addDay())
            ->whereDate('sk.tgl_surat', '>=', now()->subDays(7))
            ->get();

        foreach ($dataH1 as $item) {
            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam  = $this->ambilJam($item->kd_dokter, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $this->kirimListMessage($no, [
                'title'       => '🔔 Pengingat H-1',
                'description' => "Halo kak {$item->nm_pasien}, besok hari {$hari} jadwal kontrol kakak:\n\n"
                    . "🏥 Poli    : {$item->nm_poli}\n"
                    . "👨‍⚕️ Dokter  : {$item->nm_dokter}\n"
                    . "📅 Tanggal : {$hari}, {$item->tgl_rencana}\n"
                    . "⏰ Jam     : {$jam}\n\n"
                    . "Apakah kakak ingin melakukan perubahan jadwal?",
                'buttonText'  => 'Pilih',
                'lists'       => [
                    ['title' => 'Ubah jadwal', 'description' => 'Saya ingin mengubah jadwal kontrol'],
                    ['title' => 'Tetap',       'description' => 'Saya tetap dengan jadwal yang ada'],
                ],
                'footer' => 'RSU GMC',
            ], $item->nm_pasien);

            WaConversationState::updateOrCreate(
                ['phone' => $no],
                [
                    'state'       => 'awaiting_reschedule_confirmation',
                    'nm_pasien'   => $item->nm_pasien,
                    'nm_poli'     => $item->nm_poli,
                    'nm_dokter'   => $item->nm_dokter,
                    'tgl_rencana' => $item->tgl_rencana,
                    'kd_dokter'   => $item->kd_dokter,
                    'expires_at'  => now()->addHours(48),
                ]
            );

            sleep(2);
        }

        echo "✅ Selesai!\n";
    }

    // =========================
    // KIRIM LIST MESSAGE (button pilihan)
    // =========================
    private function kirimListMessage($no, array $message, $nama)
    {
        $res = Http::withHeaders([
            'Authorization' => self::WABLAS_TOKEN . '.' . self::WABLAS_SECRET,
            'Content-Type'  => 'application/json',
        ])->post(self::WABLAS_LIST_URL, [
            'data' => [[
                'phone'   => $no,
                'message' => $message,
            ]],
        ]);

if ($res->successful()) {
    echo "✔ Reminder terkirim ke: $no - $nama\n";
} else {
    echo "❌ Gagal ke: $no - " . $res->body() . "\n";
}
    }

    // =========================
    // KIRIM PESAN TEKS BIASA
    // =========================
    private function kirimWa($no, $pesan, $nama)
    {
        $res = Http::withHeaders([
            'Authorization' => self::WABLAS_TOKEN,
            'secret-key'    => self::WABLAS_SECRET,
        ])->post(self::WABLAS_URL, [
            'phone'   => $no,
            'message' => $pesan,
        ]);

if ($res->successful()) {
    echo "✔ Terkirim ke: $no - $nama\n";
} else {
    echo "❌ Gagal ke: $no\n";
    echo $res->body() . "\n";
}

        sleep(2);
    }

    // =========================
    // AMBIL JAM DOKTER
    // =========================
    private function ambilJam($kd_dokter, $tanggal)
    {
        $hari = $this->getHariIndo($tanggal);

        $jadwal = DB::table('jadwal')
            ->where('kd_dokter', $kd_dokter)
            ->where('hari_kerja', $hari)
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
