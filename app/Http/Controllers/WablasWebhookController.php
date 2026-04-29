<?php

namespace App\Http\Controllers;

use App\Models\NpsUlasan;
use App\Models\WaConversationState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WablasWebhookController extends Controller
{
    const NO_GCARE        = '628117978776';
    const NO_PENDAFTARAN  = '6281373550684';

    const WABLAS_URL      = 'https://jogja.wablas.com/api/send-message';
    const WABLAS_IMG_URL  = 'https://jogja.wablas.com/api/send-image';
    const WABLAS_TOKEN    = 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP';
    const WABLAS_SECRET   = '4vWr3WU7';

    const JADWAL_IMAGE_URL = 'https://i.ibb.co.com/LDDj1w54/jadwal.jpg';

    public function handle(Request $request)
    {
        Log::info('[Wablas Webhook] Payload:', $request->all());

        $phone   = $request->input('data.phone') ?? $request->input('phone');
        $message = trim($request->input('data.message') ?? $request->input('message') ?? '');

        if (!$phone || !$message) {
            return response()->json(['status' => 'ignored'], 200);
        }

        // Kalau pesan format list message Wablas: "Judul <~ Pilihan#Deskripsi"
        // Ekstrak bagian setelah '<~' dan sebelum '#'
        if (str_contains($message, '<~')) {
            preg_match('/<~\s*(.+?)(?:#|$)/', $message, $extracted);
            if (!empty($extracted[1])) {
                $message = trim($extracted[1]);
                Log::info('[Wablas Webhook] Extracted list reply: ' . $message);
            }
        }

        $state = WaConversationState::aktif($phone);

        Log::info('[Webhook Debug] phone: ' . $phone . ' | message: ' . $message . ' | state: ' . ($state ? $state->state : 'NULL'));

        if (!$state) {
            return response()->json(['status' => 'no_active_session'], 200);
        }

        match ($state->state) {
            'awaiting_reschedule_confirmation' => $this->handleKonfirmasi($state, $message),
            'awaiting_new_date'                => $this->handleTanggalBaru($state, $message),
            'awaiting_nps_score'               => $this->handleNpsSkor($state, $message),
            'awaiting_nps_comment'             => $this->handleNpsKomentar($state, $message),
            default                            => null,
        };

        return response()->json(['status' => 'ok'], 200);
    }

    private function handleKonfirmasi(WaConversationState $state, string $message): void
    {
        Log::info('[handleKonfirmasi] message: ' . $message);

        $msg = mb_strtolower($message);

        Log::info('[handleKonfirmasi] msg lowercase: ' . $msg);
        Log::info('[handleKonfirmasi] contains ubah: ' . (str_contains($msg, 'ubah') ? 'YES' : 'NO'));

        if (str_contains($msg, 'ubah')) {

            $this->kirimGambar(
                $state->phone,
                self::JADWAL_IMAGE_URL,
                'Berikut jadwal lengkap dokter 📅'
            );

            $this->kirimWa(
                $state->phone,
                "Silakan balas dengan tanggal rencana kontrol yang baru ya kak 🙏\n\nContoh: *Kamis, 01/12/2026*"
            );

            $state->update([
                'state'      => 'awaiting_new_date',
                'expires_at' => now()->addHours(24),
            ]);

        } elseif (str_contains($msg, 'tetap')) {

            $this->kirimWa(
                $state->phone,
                "Baik kak, sampai bertemu di RSU GMC ya! 😊\n\nKakak bisa cek jadwal dokter terkini di status WhatsApp atau sosial media kami."
            );

            $state->delete();

        } else {
            $this->kirimWa(
                $state->phone,
                "Maaf kak, silakan pilih melalui tombol menu yang tersedia ya 🙏"
            );
        }
    }

    private function handleTanggalBaru(WaConversationState $state, string $message): void
    {
        if (!preg_match('/\d{2}[\/\-]\d{2}[\/\-]\d{4}/', $message)) {
            $this->kirimWa(
                $state->phone,
                "Format tanggal belum sesuai kak 🙏\n\nContoh: *Kamis, 01/12/2026*"
            );
            return;
        }

        $notif = "📋 *Permintaan Ubah Jadwal Kontrol*\n\n"
            . "Pasien      : {$state->nm_pasien}\n"
            . "Poli        : {$state->nm_poli}\n"
            . "Dokter      : {$state->nm_dokter}\n"
            . "Jadwal lama : {$state->tgl_rencana}\n"
            . "Jadwal baru : {$message}\n"
            . "No HP       : {$state->phone}";

        $this->kirimWa(self::NO_GCARE, $notif);
        $this->kirimWa(self::NO_PENDAFTARAN, $notif);

        $this->kirimWa(
            $state->phone,
            "✅ Permintaan perubahan jadwal sudah kami teruskan ke petugas.\n\nMohon tunggu konfirmasi selanjutnya ya kak 🙏"
        );

        $state->delete();
    }

    private function handleNpsSkor(WaConversationState $state, string $message): void
    {
        preg_match('/\b(\d{1,2})\b/', $message, $m);
        $skor = isset($m[1]) ? (int) $m[1] : -1;

        if ($skor < 0 || $skor > 10) {
            $this->kirimWa(
                $state->phone,
                "Maaf kak, mohon pilih angka *0 sampai 10* melalui menu yang tersedia ya 🙏"
            );
            return;
        }

        $nps = NpsUlasan::find((int) $state->kd_dokter);

        if ($nps) {
            $nps->update([
                'skor'    => $skor,
                'skor_at' => now(),
                'segmen'  => NpsUlasan::hitungSegmen($skor),
            ]);
        }

        $pertanyaan = $skor <= 8
            ? "Terima kasih atas penilaiannya kak 🙏\n\nApa hal yang bisa kami perbaiki agar layanan kami lebih menyenangkan bagi kakak?"
            : "Terima kasih banyak, kak! 🌟 Kami senang bisa melayani kakak.\n\nApa hal yang membuat kakak merasa nyaman sehingga akan merekomendasikan kami ke orang lain?";

        $this->kirimWa($state->phone, $pertanyaan);

        $state->update([
            'state'      => 'awaiting_nps_comment',
            'expires_at' => now()->addHours(24),
        ]);
    }

    private function handleNpsKomentar(WaConversationState $state, string $message): void
    {
        $nps = NpsUlasan::find((int) $state->kd_dokter);

        if ($nps) {
            $nps->update([
                'komentar'    => $message,
                'komentar_at' => now(),
            ]);
        }

        $this->kirimWa(
            $state->phone,
            "Masukan kakak sudah kami catat 📝 Terima kasih sudah membantu kami menjadi lebih baik 🙏\n\nSemoga kakak dan keluarga selalu sehat ya kak 😊"
        );

        $state->delete();
    }

    private function kirimWa(string $phone, string $pesan): void
    {
        try {
            Http::withHeaders([
                'Authorization' => self::WABLAS_TOKEN,
                'secret-key'    => self::WABLAS_SECRET,
            ])->post(self::WABLAS_URL, [
                'phone'   => $phone,
                'message' => $pesan,
            ]);
        } catch (\Exception $e) {
            Log::error('[Wablas] Gagal kirim WA ke ' . $phone . ': ' . $e->getMessage());
        }
    }

    private function kirimGambar(string $phone, string $imageUrl, string $caption = ''): void
    {
        try {
            Log::info('[kirimGambar] Mencoba kirim ke ' . $phone . ' | URL: ' . $imageUrl);

            $res = Http::withHeaders([
                'Authorization' => self::WABLAS_TOKEN,
                'secret-key'    => self::WABLAS_SECRET,
            ])->post(self::WABLAS_IMG_URL, [
                'phone'   => $phone,
                'image'   => $imageUrl,
                'caption' => $caption,
            ]);

            Log::info('[kirimGambar] Response: ' . $res->status() . ' | ' . $res->body());

        } catch (\Exception $e) {
            Log::error('[Wablas] Gagal kirim gambar ke ' . $phone . ': ' . $e->getMessage());
        }
    }
}