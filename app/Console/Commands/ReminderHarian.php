<?php

namespace App\Console\Commands;

use App\Models\WaConversationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReminderHarian extends Command
{
    protected $signature   = 'reminder:harian';
    protected $description = 'Kirim reminder H-3 dan H-1 kontrol pasien (jalan jam 16.00)';

    const WABLAS_TOKEN    = 'VB8zjsrnjSBJ0ebc9VlnxuRcqM3hUXkGLSW9OeQh466Ht22MDLIm7Rd1UJ6KWNfP';
    const WABLAS_SECRET   = '4vWr3WU7';
    const WABLAS_URL      = 'https://jogja.wablas.com/api/send-message';
    const WABLAS_LIST_URL = 'https://jogja.wablas.com/api/v2/send-list';

    public function handle()
    {
        // =========================
        // 1. REMINDER H-3
        // =========================
        $dataH3 = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->select(
                'sk.nm_poli_bpjs as nm_poli',
                'sk.nm_dokter_bpjs',
                'sk.tgl_rencana',
                'p.nm_pasien',
                'p.no_tlp'
            )
            ->whereDate('sk.tgl_rencana', now()->addDays(3))
            ->whereDate('sk.tgl_surat', '>=', now()->subDays(30))
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->get();

        foreach ($dataH3 as $item) {
            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam  = $this->ambilJam($item->nm_dokter_bpjs, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $this->kirimListMessage($no, [
                'title'       => '🔔 Pengingat H-3',
                'description' => "Halo kak {$item->nm_pasien}, kembali mengingatkan jadwal kontrol kakak:\n\n"
                    . "🏥 Poli    : {$item->nm_poli}\n"
                    . "👨‍⚕️ Dokter  : {$item->nm_dokter_bpjs}\n"
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
                    'nm_dokter'   => $item->nm_dokter_bpjs,
                    'tgl_rencana' => $item->tgl_rencana,
                    'kd_dokter'   => '',
                    'expires_at'  => now()->addHours(48),
                ]
            );

            sleep(2);
        }

        // =========================
        // 2. REMINDER H-1
        // =========================
        $dataH1 = DB::table('bridging_surat_kontrol_bpjs as sk')
            ->join('bridging_sep as bs', 'sk.no_sep', '=', 'bs.no_sep')
            ->join('reg_periksa as rp', 'bs.no_rawat', '=', 'rp.no_rawat')
            ->join('pasien as p', 'rp.no_rkm_medis', '=', 'p.no_rkm_medis')
            ->select(
                'sk.nm_poli_bpjs as nm_poli',
                'sk.nm_dokter_bpjs',
                'sk.tgl_rencana',
                'p.nm_pasien',
                'p.no_tlp'
            )
            ->whereDate('sk.tgl_rencana', now()->addDay())
            ->whereDate('sk.tgl_surat', '>=', now()->subDays(30))
            ->whereNotNull('p.no_tlp')
            ->where('p.no_tlp', '!=', '')
            ->get();

        foreach ($dataH1 as $item) {
            $no = $this->formatNomor($item->no_tlp);
            if (!$no) continue;

            $jam  = $this->ambilJam($item->nm_dokter_bpjs, $item->tgl_rencana);
            $hari = $this->getHariIndo($item->tgl_rencana);

            $this->kirimListMessage($no, [
                'title'       => '🔔 Pengingat H-1',
                'description' => "Halo kak {$item->nm_pasien}, besok hari {$hari} jadwal kontrol kakak:\n\n"
                    . "🏥 Poli    : {$item->nm_poli}\n"
                    . "👨‍⚕️ Dokter  : {$item->nm_dokter_bpjs}\n"
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
                    'nm_dokter'   => $item->nm_dokter_bpjs,
                    'tgl_rencana' => $item->tgl_rencana,
                    'kd_dokter'   => '',
                    'expires_at'  => now()->addHours(48),
                ]
            );

            sleep(2);
        }

        echo "✅ Selesai!\n";
    }

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
