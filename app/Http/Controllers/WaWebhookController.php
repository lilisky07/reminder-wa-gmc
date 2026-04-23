<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WaWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $phone  = $request->input('phone');
        $message = strtolower(trim($request->input('message')));

        $no = $this->formatNomor($phone);

        // =========================
        // CEK PASIEN TERAKHIR (AMBIL DARI LOG ATAU DB)
        // =========================
        $pasien = DB::table('pasien')
            ->where('no_tlp', 'LIKE', '%' . substr($no, -10))
            ->first();

        if (!$pasien) return response()->json(['status' => 'no pasien']);

        // =========================
        // 1. UBAH JADWAL
        // =========================
        if ($message == '1' || str_contains($message, 'ubah')) {

            $this->kirimWaImage($no);

            $this->kirimWa($no,
                "Berikut jadwal lengkapnya.\n\nSilakan balas dengan tanggal rencana kontrol yang baru\ncontoh: Kamis, 01/12/2026"
            );

            return;
        }

        // =========================
        // 2. TETAP
        // =========================
        if ($message == '2' || str_contains($message, 'tetap')) {

            $this->kirimWa($no,
                "Baik, kakak bisa cek jadwal dokter terkini dengan melihat update status whatsapp atau sosial media kami."
            );

            return;
        }

        // =========================
        // 3. FORMAT TANGGAL (UBAH JADWAL)
        // =========================
        if ($this->isTanggal($message)) {

            $pesanAdmin = "📢 PERUBAHAN JADWAL

Pasien: {$pasien->nm_pasien}
No RM: {$pasien->no_rkm_medis}
No HP: {$no}

Mengajukan perubahan ke:
👉 {$message}

Mohon segera update di SIMRS.";

            // kirim ke admin
            $this->kirimWa('628117978776', $pesanAdmin); // GCare
            $this->kirimWa('6281373550684', $pesanAdmin); // Pendaftaran

            // balas ke pasien
            $this->kirimWa($no,
                "Permintaan perubahan jadwal sudah kami kirim ke petugas ya kak 🙏\n\nMohon ditunggu konfirmasi selanjutnya."
            );

            return;
        }

        return response()->json(['status' => 'ok']);
    }

    // =========================
    // DETEKSI FORMAT TANGGAL
    // =========================
    private function isTanggal($text)
    {
        return preg_match('/\d{2}\/\d{2}\/\d{4}/', $text);
    }

    // =========================
    // KIRIM WA TEXT
    // =========================
    private function kirimWa($no, $pesan)
    {
        Http::withHeaders([
            'Authorization' => config('services.wablas.token'),
            'secret-key' => config('services.wablas.secret')
        ])->post(config('services.wablas.url'), [
            'phone' => $no,
            'message' => $pesan,
        ]);
    }

    // =========================
    // KIRIM GAMBAR JADWAL
    // =========================
    private function kirimWaImage($no)
    {
        Http::withHeaders([
            'Authorization' => config('services.wablas.token'),
            'secret-key' => config('services.wablas.secret')
        ])->post('https://jogja.wablas.com/api/send-image', [
            'phone' => $no,
            'image' => 'https://yourdomain.com/jadwal.jpg',
            'caption' => 'Jadwal dokter terbaru'
        ]);
    }

    private function formatNomor($no)
    {
        $no = preg_replace('/[^0-9]/', '', $no);

        if (substr($no, 0, 2) == '08') {
            return '62' . substr($no, 1);
        }

        return $no;
    }
}