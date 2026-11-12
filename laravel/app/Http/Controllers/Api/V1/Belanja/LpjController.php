<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LPJ (Laporan Pertanggungjawaban) — referensi SPP GU.
 *
 * Diporting dari blok `action=lpj_list` pada api/belanja.php.
 */
class LpjController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/lpj
     */
    public function index()
    {
        $skpd = $this->skpd();

        $rows = DB::table('lpj as s')
            ->where('s.skpd', $skpd)
            ->orderByDesc('s.tanggal')
            ->orderByDesc('s.id')
            ->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/lpj  (action=lpj_create)
     */
    public function store(Request $request)
    {
        $skpd    = $this->skpd();
        $tanggal = trim((string) $request->input('tanggal', ''));
        $jumlah  = (float) $request->input('jumlah', 0);

        if ($tanggal === '') {
            return $this->fail('Tanggal LPJ wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        $nomor = $this->nomorDokumen('LPJ');

        $id = (int) DB::table('lpj')->insertGetId([
            'skpd'      => $skpd,
            'nomor_lpj' => $nomor,
            'tanggal'   => $tanggal,
            'uraian'    => trim((string) $request->input('uraian', '')),
            'jumlah'    => $jumlah,
        ]);

        return $this->ok('LPJ berhasil dibuat.', ['id' => $id, 'nomor_lpj' => $nomor], 201);
    }
}
