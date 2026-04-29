<?php

namespace App\Http\Controllers;

use App\Models\NpsUlasan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NpsLaporanController extends Controller
{
    /**
     * GET /api/nps/laporan?dari=YYYY-MM-DD&sampai=YYYY-MM-DD
     * Dipanggil Google Apps Script untuk ekspor ke Google Sheets.
     */
    public function index(Request $request): JsonResponse
    {
        $dari   = $request->input('dari',   now()->subDays(30)->toDateString());
        $sampai = $request->input('sampai', now()->toDateString());

        $base = fn() => NpsUlasan::whereBetween('kirim_at', [
            $dari   . ' 00:00:00',
            $sampai . ' 23:59:59',
        ]);

        $data = $base()
            ->whereNotNull('skor')
            ->orderByDesc('skor_at')
            ->get([
                'nm_pasien', 'nm_poli', 'jenis_rawat',
                'skor', 'segmen', 'komentar',
                'skor_at', 'komentar_at', 'sudah_direspons_cs',
            ]);

        $totalKirim   = $base()->count();
        $totalRespons = $base()->whereNotNull('skor')->count();
        $detractors   = $base()->where('segmen', 'detractor')->count();
        $passives     = $base()->where('segmen', 'passive')->count();
        $promoters    = $base()->where('segmen', 'promoter')->count();
        $rataRata     = $totalRespons > 0 ? round($base()->whereNotNull('skor')->avg('skor'), 1) : null;
        $npsScore     = $totalRespons > 0
            ? round((($promoters - $detractors) / $totalRespons) * 100)
            : null;

        return response()->json([
            'periode'  => ['dari' => $dari, 'sampai' => $sampai],
            'konklusi' => [
                'total_kirim'    => $totalKirim,
                'total_respons'  => $totalRespons,
                'response_rate'  => $totalKirim > 0
                    ? round(($totalRespons / $totalKirim) * 100, 1) . '%' : '0%',
                'rata_rata_skor' => $rataRata,
                'nps_score'      => $npsScore,
                'detractors'     => $detractors,
                'passives'       => $passives,
                'promoters'      => $promoters,
            ],
            'data' => $data,
        ]);
    }
}
