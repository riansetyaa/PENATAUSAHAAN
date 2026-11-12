<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AKLAP — laporan ringkas: LRA (realisasi vs anggaran) dan Neraca (saldo awal).
 *
 * Diporting dari blok `action=lra_rekap` dan `action=neraca_rekap` pada
 * peta.simtkd.com/api/aklap.php.
 *
 * Aturan penting (sama dengan legacy): LAPORAN KEUANGAN hanya memakai jurnal
 * yang sudah di-approve. Belanja dihitung dari SP2D yang sudah dicairkan DAN
 * jurnalnya sudah di-approve.
 */
class LaporanController extends Controller
{
    /**
     * GET /api/v1/aklap/lra?tahun=
     */
    public function lra(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $tahun = (string) $request->query('tahun', date('Y'));

        // Realisasi penerimaan per akun.
        $realisasi = [];

        $rows = DB::table('stbp')
            ->where('status', '<>', 'dihapus')
            ->where('akun_kode', '<>', '')
            ->where('jurnal_status', 'sudah_approve')
            ->where('skpd', $skpd)
            ->groupBy('akun_kode')
            ->get(['akun_kode', DB::raw('SUM(jumlah) as total')]);

        foreach ($rows as $r) {
            $realisasi[(string) $r->akun_kode] = (float) $r->total;
        }

        $totalPagu      = (float) DB::table('kegiatan')->where('skpd', $skpd)->sum('pagu');
        $totalRealisasi = (float) DB::table('kegiatan')->where('skpd', $skpd)->sum('realisasi');
        $jumlahKegiatan = (int) DB::table('kegiatan')->where('skpd', $skpd)->count();

        $totalBelanja = (float) DB::table('sp2d')
            ->where('status', 'sudah_dicairkan')
            ->where('jurnal_status', 'sudah_approve')
            ->where('skpd', $skpd)
            ->sum('jumlah');

        // Anggaran LRA per akun (dari pengaturan anggaran-neraca).
        $anggaran = [];

        $rows = DB::table('anggaran_lra')
            ->where('skpd', $skpd)
            ->where('tahun', $tahun)
            ->groupBy('kode_akun')
            ->get(['kode_akun', DB::raw('SUM(anggaran) as total')]);

        foreach ($rows as $r) {
            $anggaran[(string) $r->kode_akun] = (float) $r->total;
        }

        return $this->okData([
            'realisasi_by_akun' => $realisasi,
            'anggaran_by_akun'  => $anggaran,
            'total_pagu'        => $totalPagu,
            'total_realisasi'   => $totalRealisasi,
            'jumlah_kegiatan'   => $jumlahKegiatan,
            'total_penerimaan'  => array_sum($realisasi),
            'total_belanja'     => $totalBelanja,
        ]);
    }

    /**
     * GET /api/v1/aklap/neraca?tahun=
     *
     * Saldo awal per akun (tabel neraca_awal) untuk tahun yang diminta.
     */
    public function neraca(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $tahun = (string) $request->query('tahun', date('Y'));

        $rows = DB::table('neraca_awal')
            ->where('skpd', $skpd)
            ->where('tahun', $tahun)
            ->groupBy('kode_akun', 'nama_akun', 'jenis')
            ->orderBy('kode_akun')
            ->get(['kode_akun', 'nama_akun', DB::raw('SUM(saldo) as saldo'), 'jenis'])
            ->map(fn ($r): array => [
                'kode_akun' => (string) $r->kode_akun,
                'nama_akun' => (string) $r->nama_akun,
                'saldo'     => (float) $r->saldo,
                'jenis'     => (string) $r->jenis,
            ])->all();

        return $this->okData(['data' => $rows]);
    }
}
