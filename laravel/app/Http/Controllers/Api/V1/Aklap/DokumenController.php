<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — daftar dokumen sumber (STBP + STS) untuk saran "Nomor Dokumen".
 *
 * Diporting dari blok `action=dokumen_list` pada peta.simtkd.com/api/aklap.php.
 * Kelompok STBP ditampilkan lebih dulu, lalu STS (masing-masing maksimal 150
 * baris) — tidak ada pengurutan gabungan, sama seperti legacy.
 */
class DokumenController extends Controller
{
    /**
     * GET /api/v1/aklap/dokumen?q=
     */
    public function index(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $q    = trim((string) $request->query('q', ''));
        $docs = [];

        try {
            $query = DB::table('stbp')
                ->where('status', '<>', 'dihapus')
                ->where('nomor_stbp', '<>', '')
                ->where('skpd', $skpd);

            if ($q !== '') {
                $query->where(function ($builder) use ($q): void {
                    $builder->where('nomor_stbp', 'like', '%' . $q . '%')
                        ->orWhere('uraian', 'like', '%' . $q . '%');
                });
            }

            foreach ($query->orderByDesc('tanggal')->orderByDesc('id')->limit(150)
                ->get(['nomor_stbp', 'tanggal', 'jumlah', 'akun_kode', 'akun_nama', 'uraian']) as $r) {
                $docs[] = [
                    'nomor'     => (string) $r->nomor_stbp,
                    'jenis'     => 'STBP',
                    'tanggal'   => (string) $r->tanggal,
                    'jumlah'    => (float) $r->jumlah,
                    'akun_kode' => (string) $r->akun_kode,
                    'akun_nama' => (string) $r->akun_nama,
                    'uraian'    => (string) $r->uraian,
                ];
            }
        } catch (Throwable) {
            // tabel belum ada -> lewati (perilaku legacy)
        }

        try {
            $query = DB::table('sts as t')
                ->where('t.status', 'aktif')
                ->where('t.nomor_sts', '<>', '')
                ->where('t.skpd', $skpd);

            if ($q !== '') {
                $query->where(function ($builder) use ($q): void {
                    $builder->where('t.nomor_sts', 'like', '%' . $q . '%')
                        ->orWhere('t.keterangan', 'like', '%' . $q . '%');
                });
            }

            $rows = $query->orderByDesc('t.tanggal_sts')->orderByDesc('t.id')->limit(150)
                ->get([
                    't.nomor_sts',
                    DB::raw('t.tanggal_sts as tanggal'),
                    DB::raw('t.total as jumlah'),
                    DB::raw('t.keterangan as uraian'),
                    DB::raw('(SELECT d.akun_kode FROM sts_detail d WHERE d.sts_id = t.id ORDER BY d.id ASC LIMIT 1) AS akun_kode'),
                    DB::raw('(SELECT d.akun_nama FROM sts_detail d WHERE d.sts_id = t.id ORDER BY d.id ASC LIMIT 1) AS akun_nama'),
                ]);

            foreach ($rows as $r) {
                $docs[] = [
                    'nomor'     => (string) $r->nomor_sts,
                    'jenis'     => 'STS',
                    'tanggal'   => (string) $r->tanggal,
                    'jumlah'    => (float) $r->jumlah,
                    'akun_kode' => (string) ($r->akun_kode ?? ''),
                    'akun_nama' => (string) ($r->akun_nama ?? ''),
                    'uraian'    => (string) ($r->uraian ?? ''),
                ];
            }
        } catch (Throwable) {
            // tabel belum ada -> lewati (perilaku legacy)
        }

        return $this->okData(['data' => $docs]);
    }
}
