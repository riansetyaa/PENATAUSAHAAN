<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Daftar Rekanan (penyedia barang/jasa).
 *
 * Diporting dari blok `action=rekanan_list` pada api/belanja.php.
 */
class RekananController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/rekanan
     */
    public function index()
    {
        $skpd = $this->skpd();

        $rows = DB::table('rekanan as r')
            ->where('r.skpd', $skpd)
            ->orderByDesc('r.id')
            ->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/rekanan  (action=rekanan_create)
     */
    public function store(Request $request)
    {
        $skpd = $this->skpd();

        $nama   = trim((string) $request->input('nama_rekanan', ''));
        $jenis  = ((string) $request->input('jenis', '')) === 'perseorangan' ? 'perseorangan' : 'perusahaan';

        if ($nama === '') {
            return $this->fail('Nama rekanan wajib diisi.', ['field' => 'nama_rekanan'], 422);
        }

        $id = (int) DB::table('rekanan')->insertGetId([
            'user_id'        => $request->user()?->id,
            'skpd'           => $skpd,
            'nama_rekanan'   => $nama,
            'npwp'           => trim((string) $request->input('npwp', '')),
            'alamat'         => trim((string) $request->input('alamat', '')),
            'bank'           => trim((string) $request->input('bank', '')),
            'nomor_rekening' => trim((string) $request->input('nomor_rekening', '')),
            'nama_rekening'  => trim((string) $request->input('nama_rekening', '')),
            'jenis'          => $jenis,
        ]);

        return $this->ok('Rekanan berhasil disimpan.', ['id' => $id], 201);
    }
}
