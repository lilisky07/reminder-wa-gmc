<?php

namespace App\Console\Commands;

use App\Models\NpsUlasan;
use App\Models\WaConversationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NpsKirim extends Command
{
    protected $signature   = 'nps:kirim {--no-rawat= : Filter ke 1 no_rawat tertentu (untuk testing)}';
    protected $description = 'Kirim WA NPS ke pasien yang billing sudah closing (hanya sekali per kunjungan)';

    const WABLAS_TOKEN    = 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP';
    const WABLAS_SECRET   = '4vWr3WU7';
    const WABLAS_LIST_URL = 'https://jogja.wablas.com/api/v2/send-list';

    public function handle(): void
    {
        $filterNoRawat = $this->option('no-rawat');

        $query = DB::table('reg_periksa as rp')
            ->leftJoin('bridging_sep as bs', 'rp.no_rawat', '=', 'bs.no_rawat')
            ->leftJoin('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->leftJoin('poliklinik as pol', 'rp.kd_poli', '=', 'pol.kd_poli')
            ->select(
                'rp.no_rawat',
                'rp.no_rkm_medis',
                'bs.no_sep',
                'p.nm_pasien',
                'p.no_tlp',
                'pol.nm_poli',
                DB::raw("CASE WHEN bs.no_sep IS NOT NULL THEN 'ranap' ELSE 'rajal' END as jenis_rawat")
            )
            ->where('rp.status_bayar', 'Sudah Bayar')
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '');

        if ($filterNoRawat) {
            $query->where('rp.no_rawat', $filterNoRawat);
            $this->info("🧪 Mode testing — filter ke no_rawat: {$filterNoRawat}");
        } else {
            $query->where('rp.tgl_registrasi', '>=', now()->subDays(7));
        }

        $pasiens = $query->get();

        if ($pasiens->isEmpty()) {
            $this->warn('Tidak ada data pasien yang ditemukan.');
            return;
        }

        $terkirim = 0;
        $dilewati  = 0;

        foreach ($pasiens as $pasien) {
            if (NpsUlasan::sudahDikirim($pasien->no_rawat)) {
                $this->warn("⚠ Skip → {$pasien->nm_pasien} (sudah pernah dikirim)");
                $dilewati++;
                continue;
            }

            $no = $this->formatNomor($pasien->no_tlp);
            if (!$no) {
                $this->warn("⚠ Skip → {$pasien->nm_pasien} (nomor tidak valid: {$pasien->no_tlp})");
                $dilewati++;
                continue;
            }

            $nps = NpsUlasan::create([
                'no_rawat'     => $pasien->no_rawat,
                'no_sep'       => $pasien->no_sep,
                'no_rkm_medis' => $pasien->no_rkm_medis,
                'nm_pasien'    => $pasien->nm_pasien,
                'nm_poli'      => $pasien->nm_poli ?? 'Umum',
                'jenis_rawat'  => $pasien->jenis_rawat,
                'phone'        => $no,
                'sudah_kirim'  => true,
                'kirim_at'     => now(),
            ]);

            $berhasil = $this->kirimListNps($no, $pasien->nm_pasien, $pasien->nm_poli ?? 'Umum');

            if ($berhasil) {
                WaConversationState::updateOrCreate(
                    ['phone' => $no],
                    [
                        'state'       => 'awaiting_nps_score',
                        'nm_pasien'   => $pasien->nm_pasien,
                        'nm_poli'     => $pasien->nm_poli ?? 'Umum',
                        'nm_dokter'   => '',
                        'tgl_rencana' => '',
                        'kd_dokter'   => (string) $nps->id,
                        'expires_at'  => now()->addHours(48),
                    ]
                );
                $terkirim++;
                $this->info("✓ Terkirim → {$pasien->nm_pasien} ({$no})");
            } else {
                $nps->delete();
                $dilewati++;
                $this->warn("✗ Gagal   → {$pasien->nm_pasien} ({$no})");
            }

            sleep(2);
        }

        $this->info("\nSelesai. Terkirim: {$terkirim} | Dilewati/Gagal: {$dilewati}");
        Log::info("[NPS] Kirim selesai. Terkirim: {$terkirim}, Dilewati: {$dilewati}");
    }

    private function kirimListNps(string $phone, string $nmPasien, string $nmPoli): bool
    {
        $lists = [];
        for ($i = 0; $i <= 10; $i++) {
            $label = match ($i) {
                0       => '0 — Sangat tidak mungkin',
                10      => '10 — Sangat mungkin',
                default => (string) $i,
            };
            $lists[] = ['title' => $label, 'description' => ''];
        }

        try {
            $resp = Http::withHeaders([
                'Authorization' => self::WABLAS_TOKEN . '.' . self::WABLAS_SECRET,
                'Content-Type'  => 'application/json',
            ])->post(self::WABLAS_LIST_URL, [
                'data' => [[
                    'phone'   => $phone,
                    'message' => [
                        'title'       => 'Penilaian Layanan RSU GMC',
                        'description' => "Halo kak *{$nmPasien}* 👋\n\n"
                            . "Terima kasih sudah mempercayakan kesehatan kakak kepada kami di unit *{$nmPoli}*.\n\n"
                            . "Seberapa besar kemungkinan kakak akan *merekomendasikan* layanan kami kepada keluarga atau teman?\n\n"
                            . "Pilih angka di bawah ini (0 = sangat tidak mungkin, 10 = sangat mungkin):",
                        'buttonText'  => 'Beri Penilaian',
                        'lists'       => $lists,
                        'footer'      => 'RSU GMC · Pesan ini hanya dikirim sekali',
                    ],
                ]],
            ]);

            $this->line("   HTTP {$resp->status()} | " . $resp->body());
            Log::info("[NPS] Kirim ke {$phone}: HTTP {$resp->status()} | {$resp->body()}");

            return $resp->successful();

        } catch (\Exception $e) {
            Log::error("[NPS] Exception kirim ke {$phone}: " . $e->getMessage());
            $this->error('   Exception: ' . $e->getMessage());
            return false;
        }
    }

    private function formatNomor(string $no): ?string
    {
        $no = preg_replace('/\D/', '', $no);
        if (str_starts_with($no, '08')) {
            $no = '628' . substr($no, 2);
        } elseif (str_starts_with($no, '8')) {
            $no = '62' . $no;
        }
        if (strlen($no) < 10 || strlen($no) > 15) return null;
        return $no;
    }
}
