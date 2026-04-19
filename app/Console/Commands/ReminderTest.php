<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReminderTest extends Command
{
    protected $signature = 'reminder:test';
    protected $description = 'Kirim reminder test pasien via WhatsApp';

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
            ->select(
                'p.nm_pasien',
                'p.no_tlp',
                'sk.tgl_rencana',
                'sk.nm_dokter_bpjs as nm_dokter',
                'rp.kd_dokter',
                'pl.nm_poli'
            )
            ->whereDate('sk.tgl_surat', now())
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->get();

        foreach ($dataAwal as $item) {

            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam = $this->ambilJam($item->kd_dokter, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $pesan = "Terima kasih telah memilih RSU GMC 🙏

Berikut rencana kontrol kak {$item->nm_pasien}:

🏥 Poli : {$item->nm_poli}
👨‍⚕️ Dokter : {$item->nm_dokter}
📅 Tanggal : {$hari}, {$item->tgl_rencana}
⏰ Jam : {$jam}

Sampai bertemu di RSU GMC dan hati-hati di jalan 👋";

            $this->kirimWa($no, $pesan, $item->nm_pasien);
        }

        // =========================
        // 2. REMINDER H-3
        // =========================
        $dataH3 = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->join('poliklinik as pl', 'rp.kd_poli', '=', 'pl.kd_poli')
            ->select(
                'p.nm_pasien',
                'p.no_tlp',
                'sk.tgl_rencana',
                'sk.nm_dokter_bpjs as nm_dokter',
                'rp.kd_dokter',
                'pl.nm_poli'
            )
            ->whereDate('sk.tgl_rencana', now()->addDays(3))
            ->whereDate('sk.tgl_surat', '>=', now()->subDays(7))
            ->get();

        foreach ($dataH3 as $item) {

            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam = $this->ambilJam($item->kd_dokter, $item->tgl_rencana);

            $pesan = "🔔 Pengingat H-3

Halo kak {$item->nm_pasien}, kembali mengingatkan jadwal kontrol kakak ke {$item->nm_poli} dengan {$item->nm_dokter} 3 hari lagi.

📅 {$item->tgl_rencana}
⏰ {$jam}

Apakah kakak ingin melakukan perubahan jadwal?

Balas:
1. Ubah jadwal
2. Tetap";

            $this->kirimWa($no, $pesan, $item->nm_pasien);
        }

        // =========================
        // 3. REMINDER H-1
        // =========================
        $dataH1 = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->join('poliklinik as pl', 'rp.kd_poli', '=', 'pl.kd_poli')
            ->select(
                'p.nm_pasien',
                'p.no_tlp',
                'sk.tgl_rencana',
                'sk.nm_dokter_bpjs as nm_dokter',
                'rp.kd_dokter',
                'pl.nm_poli'
            )
            ->whereDate('sk.tgl_rencana', now()->addDay())
            ->whereDate('sk.tgl_surat', '>=', now()->subDays(7))
            ->get();

        foreach ($dataH1 as $item) {

            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam = $this->ambilJam($item->kd_dokter, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $pesan = "🔔 Pengingat H-1

Halo kak {$item->nm_pasien}, besok hari {$hari} jadwal kontrol kakak ke {$item->nm_poli} dengan {$item->nm_dokter}.

⏰ {$jam}

Apakah kakak ingin melakukan perubahan jadwal?

Balas:
1. Ubah jadwal
2. Tetap";

            $this->kirimWa($no, $pesan, $item->nm_pasien);
        }
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
            return $jadwal->jam_mulai . " - " . $jadwal->jam_selesai;
        }

        return "Sesuai jadwal dokter";
    }

    private function getHariIndo($tanggal)
    {
        $hari = date('l', strtotime($tanggal));

        return match ($hari) {
            'Monday' => 'SENIN',
            'Tuesday' => 'SELASA',
            'Wednesday' => 'RABU',
            'Thursday' => 'KAMIS',
            'Friday' => 'JUMAT',
            'Saturday' => 'SABTU',
            'Sunday' => 'MINGGU',
        };
    }

    private function kirimWa($no, $pesan, $nama)
    {
        $res = Http::withHeaders([
            'Authorization' => 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP',
            'secret-key' => '4vWr3WU7'
        ])->post('https://jogja.wablas.com/api/send-message', [
            'phone' => $no,
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