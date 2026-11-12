<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan TU (Tambahan Uang Persediaan).
 *
 * Diporting dari blok `action=pengajuan_tu_list` pada api/belanja.php.
 */
class PengajuanTuController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/pengajuan-tu?status=
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $query = DB::table('pengajuan_tu as s')->select('s.*')->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/pengajuan-tu  (action=pengajuan_tu_create)
     */
    public function store(Request $request)
    {
        $skpd    = $this->skpd();
        $tanggal = trim((string) $request->input('tanggal', ''));
        $jumlah  = (float) $request->input('jumlah', 0);

        if ($tanggal === '') {
            return $this->fail('Tanggal pengajuan wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        $nomor = $this->nomorDokumen('TU');

        $id = (int) DB::table('pengajuan_tu')->insertGetId([
            'skpd'            => $skpd,
            'nomor_pengajuan' => $nomor,
            'tanggal'         => $tanggal,
            'keterangan'      => trim((string) $request->input('keterangan', '')),
            'jumlah'          => $jumlah,
            'status'          => 'belum_otorisasi',
        ]);

        return $this->ok('Pengajuan TU berhasil dibuat.', ['id' => $id, 'nomor_pengajuan' => $nomor], 201);
    }

    /**
     * POST /api/v1/belanja/pengajuan-tu/{id}/otorisasi  (action=pengajuan_tu_otorisasi)
     */
    public function otorisasi(int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if (! $this->ubahStatus('pengajuan_tu', $id, $this->skpd(), 'belum_otorisasi', 'sudah_otorisasi')) {
            return $this->fail('Pengajuan TU tidak ditemukan / sudah diotorisasi / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('Pengajuan TU berhasil diotorisasi (BUD).');
    }

    /**
     * POST /api/v1/belanja/pengajuan-tu/{id}/validasi  (action=pengajuan_tu_validasi)
     */
    public function validasi(int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if (! $this->ubahStatus('pengajuan_tu', $id, $this->skpd(), 'sudah_otorisasi', 'sudah_divalidasi')) {
            return $this->fail('Pengajuan TU tidak ditemukan / belum diotorisasi / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('Pengajuan TU berhasil divalidasi (PA/KPA).');
    }
}
