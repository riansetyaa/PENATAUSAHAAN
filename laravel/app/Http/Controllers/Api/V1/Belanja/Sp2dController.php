<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SP2D (Surat Perintah Pencairan Dana).
 *
 * Diporting dari blok `action=sp2d_list` pada api/belanja.php.
 */
class Sp2dController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/sp2d?status=
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $query = DB::table('sp2d as s')
            ->leftJoin('spm as m', 'm.id', '=', 's.spm_id')
            ->select(['s.*', 'm.nomor_spm'])
            ->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/sp2d  (action=sp2d_create)
     */
    public function store(Request $request)
    {
        $skpd     = $this->skpd();
        $tanggal  = trim((string) $request->input('tanggal', ''));
        $spmId    = (int) $request->input('spm_id', 0);
        $jumlah   = (float) $request->input('jumlah', 0);

        if ($tanggal === '') {
            return $this->fail('Tanggal SP2D wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if ($spmId <= 0) {
            return $this->fail('Pilih SPM.', ['field' => 'spm_id'], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        // SPM harus sudah diverifikasi & milik instansi yang sama.
        $spmValid = DB::table('spm')
            ->where('id', $spmId)
            ->where('status', 'sudah_diverifikasi')
            ->where('skpd', $skpd)
            ->exists();

        if (! $spmValid) {
            return $this->fail('SPM tidak valid / belum diverifikasi / bukan milik instansi Anda.', [], 422);
        }

        $nomor = $this->nomorDokumen('SP2D');

        $id = (int) DB::table('sp2d')->insertGetId([
            'user_id'    => $request->user()?->id,
            'skpd'       => $skpd,
            'nomor_sp2d' => $nomor,
            'tanggal'    => $tanggal,
            'spm_id'     => $spmId,
            'rekening'   => trim((string) $request->input('rekening', '')),
            'jumlah'     => $jumlah,
            'status'     => 'belum_diverifikasi',
        ]);

        return $this->ok('SP2D berhasil dibuat.', ['id' => $id, 'nomor_sp2d' => $nomor], 201);
    }

    /**
     * POST /api/v1/belanja/sp2d/{id}/verifikasi  (action=sp2d_verifikasi)
     */
    public function verifikasi(Request $request, int $id)
    {
        $setuju = (bool) $request->input('setuju', false);

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $berubah = $this->ubahStatus(
            'sp2d',
            $id,
            $this->skpd(),
            'belum_diverifikasi',
            $setuju ? 'sudah_diverifikasi' : 'ditolak'
        );

        if (! $berubah) {
            return $this->fail('SP2D tidak ditemukan / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok($setuju ? 'SP2D berhasil diverifikasi.' : 'SP2D ditolak.');
    }

    /**
     * POST /api/v1/belanja/sp2d/{id}/pencairan  (action=sp2d_pencairan)
     *
     * Tanggal opsional; bila diisi, tanggal SP2D ikut diperbarui
     * (setara COALESCE(?, tanggal) pada legacy).
     */
    public function pencairan(Request $request, int $id)
    {
        $tanggal = trim((string) $request->input('tanggal', ''));

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $berubah = DB::table('sp2d')
            ->where('id', $id)
            ->where('status', 'sudah_diverifikasi')
            ->where('skpd', $this->skpd())
            ->update([
                'status'  => 'sudah_dicairkan',
                'tanggal' => $tanggal !== '' ? $tanggal : DB::raw('tanggal'),
            ]) > 0;

        if (! $berubah) {
            return $this->fail('SP2D tidak ditemukan / belum diverifikasi / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('Dana SP2D berhasil dicairkan (transfer).');
    }
}
