<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DokterCuti extends Command
{
    protected $signature   = 'app:dokter-cuti';
    protected $description = 'Kirim notif WA ke pasien yang kontrol saat dokter cuti';

    const WABLAS_TOKEN    = 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP';
    const WABLAS_SECRET   = '4vWr3WU7';
    const WABLAS_URL      = 'https://jogja.wablas.com/api/send-message';
    const WABLAS_IMG_URL  = 'https://jogja.wablas.com/api/send-image';
    const JADWAL_IMAGE_URL = 'https://i.ibb.co.com/LDDj1w54/jadwal.jpg';

    // ============================================================
    // Konfigurasi cuti — ubah di sini kalau ada cuti baru
    // ============================================================
    const TGL_CUTI  = '2026-06-18';
    const NAMA_POLI = 'penyakit dalam'; // case-insensitive, partial match
    // ============================================================

    private string $logFile;

    public function handle()
    {
        $this->logFile = storage_path('logs/dokter-cuti-' . date('Y-m-d_His') . '.log');

        $this->log("=== MULAI DOKTER CUTI JOB === " . now());
        $this->log("Tanggal cuti : " . self::TGL_CUTI);
        $this->log("Filter poli  : " . self::NAMA_POLI);
        $this->log(str_repeat('-', 60));

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
            ->whereDate('sk.tgl_rencana', self::TGL_CUTI)
            ->whereRaw('LOWER(sk.nm_poli_bpjs) LIKE ?', ['%' . self::NAMA_POLI . '%'])
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->get();

        if ($data->isEmpty()) {
            $this->log('Tidak ada pasien yang perlu dinotif.');
            $this->info('Tidak ada pasien yang perlu dinotif.');
            return;
        }

        $this->log("Ditemukan {$data->count()} pasien dari DB.");
        $this->info("Ditemukan {$data->count()} pasien. Mulai kirim...\n");

        foreach ($data as $item) {
            $this->log(str_repeat('-', 60));
            $this->log("Pasien   : {$item->nm_pasien}");
            $this->log("No SEP   : {$item->no_sep}");
            $this->log("No Tlp   : {$item->no_tlp}");
            $this->log("Poli     : {$item->nm_poli}");
            $this->log("Dokter   : {$item->nm_dokter_bpjs}");

            $no = $this->formatNomor($item->no_tlp);
            if (!$no) {
                $this->log("STATUS   : ⚠ SKIP — nomor tidak valid");
                echo "⚠ Nomor tidak valid: {$item->nm_pasien} ({$item->no_tlp})\n";
                continue;
            }

            $this->log("No Format: {$no}");

            if (DB::table('wa_dokter_cuti_sent')->where('no_sep', $item->no_sep)->exists()) {
                $this->log("STATUS   : ⏭ SKIP — sudah pernah dikirim");
                echo "⏭ Skip (sudah kirim): {$item->nm_pasien}\n";
                continue;
            }

            $pesan = "Halo kak *{$item->nm_pasien}*, kami dari *RSU GMC* ingin menginformasikan:\n\n"
                . "Mohon maaf, dokter pada jadwal kontrol kakak di:\n\n"
                . "🏥 Poli    : {$item->nm_poli}\n"
                . "👨‍⚕️ Dokter  : {$item->nm_dokter_bpjs}\n"
                . "📅 Tanggal : Kamis, 18 Juni 2026\n\n"
                . "*sedang mengambil cuti* pada tanggal tersebut.\n\n"
                . "Untuk itu, mohon kakak mengambil *antrian ulang* melalui aplikasi *Mobile JKN* "
                . "guna mendapatkan jadwal kontrol selanjutnya.\n\n"
                . "Terima kasih atas pengertiannya 🙏\n"
                . "_RSU Gladish Medical Centre_";

            $this->log("PESAN    :\n" . $pesan);

            // Kirim pesan teks dulu
            $result = $this->kirimPesan($no, $pesan, $item->nm_pasien);

            if ($result['success']) {
                $this->log("STATUS   : ✔ TERKIRIM");
                $this->log("RESPONSE : " . $result['body']);

                // Kirim gambar jadwal setelah pesan teks
                sleep(1);
                $this->kirimGambar($no, $item->nm_pasien);

                DB::table('wa_dokter_cuti_sent')->insert([
                    'no_sep'      => $item->no_sep,
                    'no_tlp'      => $no,
                    'nm_pasien'   => $item->nm_pasien,
                    'nm_dokter'   => $item->nm_dokter_bpjs,
                    'tgl_kontrol' => $item->tgl_rencana,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            } else {
                $this->log("STATUS   : ❌ GAGAL");
                $this->log("RESPONSE : " . $result['body']);
            }

            sleep(2);
        }

        $this->log(str_repeat('=', 60));
        $this->log("=== SELESAI === " . now());
        echo "\n✅ Selesai! Log tersimpan di: {$this->logFile}\n";
    }

    private function kirimPesan($no, $pesan, $nama): array
    {
        $res = Http::withHeaders([
            'Authorization' => self::WABLAS_TOKEN,
            'secret-key'    => self::WABLAS_SECRET,
        ])->post(self::WABLAS_URL, [
            'phone'   => $no,
            'message' => $pesan,
        ]);

        $success = $res->successful();

        if ($success) {
            echo "✔ Terkirim: $no - $nama\n";
        } else {
            echo "❌ Gagal: $no - $nama | " . $res->body() . "\n";
        }

        return [
            'success' => $success,
            'body'    => $res->body(),
        ];
    }

    private function kirimGambar($no, $nama): void
    {
        $res = Http::withHeaders([
            'Authorization' => self::WABLAS_TOKEN,
            'secret-key'    => self::WABLAS_SECRET,
        ])->post(self::WABLAS_IMG_URL, [
            'phone'   => $no,
            'image'   => self::JADWAL_IMAGE_URL,
            'caption' => 'Berikut jadwal lengkap dokter RSU GMC 📅',
        ]);

        if ($res->successful()) {
            $this->log("GAMBAR   : ✔ Terkirim ke $no - $nama");
        } else {
            $this->log("GAMBAR   : ❌ Gagal ke $no | " . $res->body());
        }
    }

    private function log(string $text): void
    {
        $line = "[" . date('H:i:s') . "] " . $text . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND);
        echo $line;
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