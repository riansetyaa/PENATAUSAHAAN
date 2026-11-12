<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — rekap ringkasan untuk dashboard (index.html peta.simtkd.com).
 *
 * Diporting dari blok `action=rekap` pada peta.simtkd.com/api/aklap.php.
 *
 * Catatan kesetiaan: legacy membungkus penghitung dengan try/catch (0 bila
 * tabel belum ada) sementara penjumlahan tidak. Di sini SEMUANYA dibungkus
 * agar tidak pernah memunculkan 500 — pada basis data lengkap hasilnya
 * identik dengan legacy.
 */
class RekapController extends Controller
{
    /**
     * GET /api/v1/aklap/rekap
     */
    public function index()
    {
        $skpd = Tenant::requireSkpd();

        $hitung = function (string $tabel, ?string $status, string $operator = '=') use ($skpd): int {
            try {
                $query = DB::table($tabel)->where('skpd', $skpd);

                if ($status !== null) {
                    $query->where('status', $operator, $status);
                }

                return (int) $query->count();
            } catch (Throwable) {
                return 0;
            }
        };

        $jumlah = function (string $tabel, ?string $status, string $kolom) use ($skpd): float {
            try {
                $query = DB::table($tabel)->where('skpd', $skpd);

                if ($status !== null) {
                    $query->where('status', $status);
                }

                return (float) $query->sum($kolom);
            } catch (Throwable) {
                return 0.0;
            }
        };

        $totalPenerimaan = 0.0;

        try {
            $totalPenerimaan = (float) DB::table('stbp')
                ->where('skpd', $skpd)
                ->where('status', '<>', 'dihapus')
                ->where('jurnal_status', 'sudah_approve')
                ->sum('jumlah');
        } catch (Throwable) {
            $totalPenerimaan = 0.0;
        }

        $totalBelanja = 0.0;

        try {
            $totalBelanja = (float) DB::table('sp2d')
                ->where('skpd', $skpd)
                ->where('status', 'sudah_dicairkan')
                ->where('jurnal_status', 'sudah_approve')
                ->sum('jumlah');
        } catch (Throwable) {
            $totalBelanja = 0.0;
        }

        return $this->okData([
            'data' => [
                'stbp'             => $hitung('stbp', 'dihapus', '<>'),
                'sts'              => $hitung('sts', 'aktif'),
                'permohonan'       => $hitung('permohonan', null),
                'kegiatan'         => $hitung('kegiatan', null),
                'akun'             => $hitung('akun_penerimaan', null),
                'total_pagu'       => $jumlah('kegiatan', null, 'pagu'),
                'total_penerimaan' => $totalPenerimaan,
                'sp2d_cair'        => $hitung('sp2d', 'sudah_dicairkan'),
                'total_belanja'    => $totalBelanja,
            ],
        ]);
    }
}
