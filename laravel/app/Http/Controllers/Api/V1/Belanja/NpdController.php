<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NPD (Nota Pencairan Dana).
 *
 * Diporting dari blok `action=npd_list` pada api/belanja.php.
 */
class NpdController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/npd?status=
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $query = DB::table('npd as s')->select('s.*')->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/npd  (action=npd_create)
     */
    public function store(Request $request)
    {
        $skpd    = $this->skpd();
        $tanggal = trim((string) $request->input('tanggal', ''));
        $jumlah  = (float) $request->input('jumlah', 0);

        if ($tanggal === '') {
            return $this->fail('Tanggal NPD wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        $nomor = $this->nomorDokumen('NPD');

        $id = (int) DB::table('npd')->insertGetId([
            'skpd'          => $skpd,
            'user_id'       => $request->user()?->id,
            'nomor_npd'     => $nomor,
            'tanggal'       => $tanggal,
            'metode'        => ((string) $request->input('metode', '')) === 'non_panjar' ? 'non_panjar' : 'panjar',
            'keterangan'    => trim((string) $request->input('keterangan', '')),
            'kegiatan'      => trim((string) $request->input('kegiatan', '')),
            'sub_kegiatan'  => trim((string) $request->input('sub_kegiatan', '')),
            'jumlah'        => $jumlah,
            'status'        => 'diajukan',
        ]);

        return $this->ok('NPD berhasil diajukan.', ['id' => $id, 'nomor_npd' => $nomor], 201);
    }

    /**
     * POST /api/v1/belanja/npd/{id}/validasi-pa  (action=npd_validasi_pa)
     */
    public function validasiPa(Request $request, int $id)
    {
        $setuju = (bool) $request->input('setuju', false);

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $berubah = $this->ubahStatus(
            'npd',
            $id,
            $this->skpd(),
            'diajukan',
            $setuju ? 'divalidasi_pa' : 'ditolak'
        );

        if (! $berubah) {
            return $this->fail('NPD tidak ditemukan / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok($setuju ? 'NPD divalidasi (PA/KPA).' : 'NPD ditolak.');
    }

    /**
     * POST /api/v1/belanja/npd/{id}/validasi-bp  (action=npd_validasi_bp)
     */
    public function validasiBp(Request $request, int $id)
    {
        $setuju = (bool) $request->input('setuju', false);

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $berubah = $this->ubahStatus(
            'npd',
            $id,
            $this->skpd(),
            'divalidasi_pa',
            $setuju ? 'divalidasi_bp' : 'ditolak'
        );

        if (! $berubah) {
            return $this->fail('NPD tidak ditemukan / belum divalidasi PA / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok($setuju ? 'NPD divalidasi (BP/BPP).' : 'NPD ditolak.');
    }
}
