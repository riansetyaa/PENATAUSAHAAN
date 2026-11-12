<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rekening Bank SKPD.
 *
 * Diporting dari blok `action=rekening_skpd_list` pada api/belanja.php.
 */
class RekeningSkpdController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/rekening-skpd?status=
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $query = DB::table('rekening_skpd as s')->select('s.*')->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.id')->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/rekening-skpd  (action=rekening_skpd_create)
     */
    public function store(Request $request)
    {
        $skpd  = $this->skpd();
        $bank  = trim((string) $request->input('bank', ''));
        $nama  = trim((string) $request->input('nama_pemilik', ''));

        if ($bank === '' || $nama === '') {
            return $this->fail('Bank dan nama pemilik wajib diisi.', [], 422);
        }

        $id = (int) DB::table('rekening_skpd')->insertGetId([
            'skpd'         => $skpd,
            'user_id'      => $request->user()?->id,
            'bank'         => $bank,
            'nama_pemilik' => $nama,
            'status'       => 'permohonan',
        ]);

        return $this->ok('Permohonan rekening dibuat (BP).', ['id' => $id], 201);
    }

    /**
     * POST /api/v1/belanja/rekening-skpd/{id}/ajukan  (action=rekening_skpd_ajukan)
     */
    public function ajukan(int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if (! $this->ubahStatus('rekening_skpd', $id, $this->skpd(), 'permohonan', 'pengajuan')) {
            return $this->fail('Rekening tidak ditemukan / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('Rekening diajukan (PA).');
    }

    /**
     * POST /api/v1/belanja/rekening-skpd/{id}/nomor  (action=rekening_skpd_nomor)
     */
    public function nomor(Request $request, int $id)
    {
        $nomor = trim((string) $request->input('nomor_rekening', ''));

        if ($id <= 0 || $nomor === '') {
            return $this->fail('ID dan nomor rekening wajib diisi.', [], 422);
        }

        $berubah = $this->ubahStatus(
            'rekening_skpd',
            $id,
            $this->skpd(),
            'pengajuan',
            'pembuatan',
            ['nomor_rekening' => $nomor]
        );

        if (! $berubah) {
            return $this->fail('Rekening tidak ditemukan / belum diajukan / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('Nomor rekening dibuat (BUD).');
    }

    /**
     * POST /api/v1/belanja/rekening-skpd/{id}/validasi  (action=rekening_skpd_validasi)
     *
     * Validasi setuju sekaligus menetapkan nomor rekening.
     */
    public function validasi(Request $request, int $id)
    {
        $setuju = (bool) $request->input('setuju', false);
        $nomor  = trim((string) $request->input('nomor_rekening', ''));

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if ($setuju && $nomor === '') {
            return $this->fail('Nomor rekening wajib diisi saat validasi.', [], 422);
        }

        $berubah = $this->ubahStatus(
            'rekening_skpd',
            $id,
            $this->skpd(),
            'pembuatan',
            $setuju ? 'aktif' : 'ditolak',
            $setuju ? ['nomor_rekening' => $nomor] : []
        );

        if (! $berubah) {
            return $this->fail('Rekening tidak ditemukan / belum dibuat nomornya / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok($setuju ? 'Rekening divalidasi & aktif.' : 'Rekening ditolak.');
    }
}
