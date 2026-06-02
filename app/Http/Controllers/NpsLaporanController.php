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
     * Default: hanya hari ini (bukan 30 hari terakhir)
     */
    public function index(Request $request): JsonResponse
    {
        $dari   = $request->input('dari',   now()->toDateString());
        $sampai = $request->input('sampai', now()->toDateString());

        // Total pesan terkirim dalam periode
        $totalKirim = NpsUlasan::whereBetween('kirim_at', [
            $dari . ' 00:00:00',
            $sampai . ' 23:59:59',
        ])->count();

        // Ambil semua data skor sekaligus — 1 query, tidak berulang
        $skorData = NpsUlasan::whereBetween('skor_at', [
            $dari . ' 00:00:00',
            $sampai . ' 23:59:59',
        ])
        ->whereNotNull('skor')
        ->get([
            'nm_pasien', 'nm_poli', 'jenis_rawat',
            'skor', 'segmen', 'komentar',
            'skor_at', 'komentar_at', 'sudah_direspons_cs',
        ])
        ->sortByDesc('skor_at')
        ->values();

        // Hitung statistik dari collection (tidak perlu query ulang ke DB)
        $totalRespons = $skorData->count();
        $detractors   = $skorData->where('segmen', 'detractor')->count();
        $passives     = $skorData->where('segmen', 'passive')->count();
        $promoters    = $skorData->where('segmen', 'promoter')->count();
        $rataRata     = $totalRespons > 0
            ? round($skorData->avg('skor'), 1)
            : null;
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
            'data' => $skorData,
        ]);
    }
}