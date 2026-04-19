<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReminderKontrol extends Command
{
    protected $signature = 'reminder:kontrol';
    protected $description = 'Kirim reminder kontrol pasien via WhatsApp';

    public function handle()
    {
        // 🔥 1. NOTIF SAAT SURAT KONTROL DIBUAT (HARI INI)
        $dataAwal = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->select(
                'p.nm_pasien',
                'p.no_tlp',
                'sk.tgl_rencana',
                'sk.nm_dokter_bpjs as nm_dokter'
            )
            ->whereDate('sk.tgl_surat', now()) // 🔥 hari ini
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->limit(3)
            ->get();

        foreach ($dataAwal as $item) {

            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $pesan = "Terima kasih telah memilih RSU GMC 🙏

Berikut jadwal kontrol kak {$item->nm_pasien}:

📅 {$item->tgl_rencana}
👨‍⚕️ {$item->nm_dokter}

Segera daftar antrean online ya 😊

Untuk BPJS:
- Gunakan Mobile JKN
- Pastikan sesuai jadwal

Jika ada perubahan jadwal, hubungi kami ya 🙏";

            $this->kirimWa($no, $pesan, $item->nm_pasien);
        }


        // 🔥 2. REMINDER H-1
        $dataReminder = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->select(
                'p.nm_pasien',
                'p.no_tlp',
                'sk.tgl_rencana',
                'sk.nm_dokter_bpjs as nm_dokter'
            )
            ->whereDate('sk.tgl_rencana', now()->addDay()) // 🔥 besok
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->limit(3)
            ->get();

        foreach ($dataReminder as $item) {

            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $pesan = "Halo kak {$item->nm_pasien} 😊

Mengingatkan jadwal kontrol BESOK:

📅 {$item->tgl_rencana}
👨‍⚕️ {$item->nm_dokter}

Sampai bertemu di RSU GMC 👋";

            $this->kirimWa($no, $pesan, $item->nm_pasien);
        }
    }


    // 🔥 FUNCTION KIRIM WA (BIAR GA DUPLIKASI KODE)
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
            $this->info("✔ Terkirim ke: $no - $nama");
        } else {
            $this->error("❌ Gagal ke: $no");
            $this->error($res->body());
        }

        sleep(2);
    }


    // 🔥 FORMAT NOMOR
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