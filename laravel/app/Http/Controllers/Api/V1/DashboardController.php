<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Kegiatan;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * API Ringkasan APBD untuk Dashboard.
 *
 * Diporting dari api/summary.php pada sistem legacy.
 */
class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard/summary
     */
    public function summary(Request $request)
    {
        $user  = $request->user();
        $skpd  = Tenant::requireSkpd();
        $tahun = (int) date('Y');

        // ---- Ringkasan tahun berjalan ----
        $agg = Kegiatan::query()
            ->where('tahun', $tahun)
            ->selectRaw('COALESCE(SUM(pagu), 0) AS pagu, COALESCE(SUM(realisasi), 0) AS realisasi, COUNT(*) AS jumlah')
            ->first();

        $totalPagu      = (float) ($agg->pagu ?? 0);
        $totalRealisasi = (float) ($agg->realisasi ?? 0);
        $jumlahKegiatan = (int) ($agg->jumlah ?? 0);
        $persen         = $totalPagu > 0 ? round(($totalRealisasi / $totalPagu) * 100, 2) : 0;

        // ---- Tahun tersedia untuk grafik ----
        $years = Kegiatan::query()
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->all();

        if ($years === []) {
            $years = [$tahun];
        }

        $chart = [];

        foreach ($years as $y) {
            $r = Kegiatan::query()
                ->where('tahun', $y)
                ->selectRaw('COALESCE(SUM(pagu), 0) AS pagu, COALESCE(SUM(realisasi), 0) AS realisasi')
                ->first();

            $chart[(string) $y] = [
                'pagu'      => (float) ($r->pagu ?? 0),
                'realisasi' => (float) ($r->realisasi ?? 0),
            ];
        }

        // ---- Daftar kegiatan tahun berjalan (10 teratas) ----
        $kegiatan = Kegiatan::query()
            ->where('tahun', $tahun)
            ->orderByDesc('pagu')
            ->limit(10)
            ->get(['id', 'nama_kegiatan', 'tahun', 'pagu', 'realisasi', 'status']);

        // ---- Ringkasan lintas modul (Penerimaan + Belanja) ----
        // Query mentah -> filter SKPD ditulis eksplisit.
        // Bila tabel modul belum ada (migrasi belum jalan), nilai 0 — bukan 500.
        $modul = [];

        $runMod = function (string $key, string $sql, bool $asInt = false) use ($skpd, &$modul): void {
            try {
                $nilai = DB::selectOne($sql, [$skpd, $skpd])->col ?? 0;
                $modul[$key] = $asInt ? (int) $nilai : (float) $nilai;
            } catch (Throwable $e) {
                report($e);
                $modul[$key] = 0;
            }
        };

        $runMod('stbp', "SELECT COALESCE(SUM(jumlah),0) AS col FROM stbp WHERE status='sudah_divalidasi' AND (?='' OR skpd=?)");
        $runMod('sts', "SELECT COALESCE(SUM(total),0) AS col FROM sts WHERE status='aktif' AND (?='' OR skpd=?)");
        $runMod('permohonan', "SELECT COUNT(*) AS col FROM permohonan WHERE (?='' OR skpd=?)", true);
        $runMod('spd', "SELECT COALESCE(SUM(jumlah),0) AS col FROM spd WHERE (?='' OR skpd=?)");
        $runMod('spp', "SELECT COALESCE(SUM(jumlah),0) AS col FROM spp WHERE (?='' OR skpd=?)");
        $runMod('sp2d_dicairkan', "SELECT COALESCE(SUM(jumlah),0) AS col FROM sp2d WHERE status='sudah_dicairkan' AND (?='' OR skpd=?)");
        $runMod('rekanan', "SELECT COUNT(*) AS col FROM rekanan WHERE (?='' OR skpd=?)", true);

        return $this->ok('OK', [
            'user' => [
                'nama'     => (string) ($user->nama_lengkap ?? ''),
                'username' => (string) ($user->username ?? ''),
                'instansi' => (string) ($user->instansi ?? ''),
                'peran'    => (string) ($user->peran ?? ''),
            ],
            'tahun' => $tahun,
            'summary' => [
                'total_pagu'      => $totalPagu,
                'total_realisasi' => $totalRealisasi,
                'persen'          => $persen,
                'jumlah_kegiatan' => $jumlahKegiatan,
            ],
            'modul' => [
                'penerimaan' => [
                    'stbp'       => $modul['stbp'] ?? 0,
                    'sts'        => $modul['sts'] ?? 0,
                    'permohonan' => $modul['permohonan'] ?? 0,
                ],
                'belanja' => [
                    'spd'            => $modul['spd'] ?? 0,
                    'spp'            => $modul['spp'] ?? 0,
                    'sp2d_dicairkan' => $modul['sp2d_dicairkan'] ?? 0,
                    'rekanan'        => $modul['rekanan'] ?? 0,
                ],
            ],
            'chart' => $chart,
            'kegiatan' => $kegiatan->map(fn ($k): array => [
                'id'           => (int) $k->id,
                'nama_kegiatan' => $k->nama_kegiatan,
                'tahun'        => (int) $k->tahun,
                'pagu'         => (float) $k->pagu,
                'realisasi'    => (float) $k->realisasi,
                'status'       => $k->status,
            ])->all(),
        ]);
    }
}
