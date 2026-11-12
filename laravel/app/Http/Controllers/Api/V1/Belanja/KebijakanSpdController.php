<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kebijakan SPD (periode: Bulanan / Triwulanan / Tahunan).
 *
 * Diporting dari blok `action=kebijakan_spd_list` pada api/belanja.php.
 */
class KebijakanSpdController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/kebijakan-spd
     */
    public function index()
    {
        $skpd = $this->skpd();

        $rows = DB::table('kebijakan_spd as s')
            ->where('s.skpd', $skpd)
            ->orderByDesc('s.id')
            ->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/kebijakan-spd  (action=kebijakan_spd_create)
     */
    public function store(Request $request)
    {
        $skpd            = $this->skpd();
        $jenisPenerbitan = trim((string) $request->input('jenis_penerbitan', ''));
        $jenisPeriode    = trim((string) $request->input('jenis_periode', ''));
        $tanggal         = trim((string) $request->input('tanggal', ''));

        if ($jenisPenerbitan === '' || $jenisPeriode === '') {
            return $this->fail('Jenis penerbitan & periode wajib diisi.', [], 422);
        }

        $id = (int) DB::table('kebijakan_spd')->insertGetId([
            'skpd'             => $skpd,
            'jenis_penerbitan' => $jenisPenerbitan,
            'jenis_periode'    => $jenisPeriode,
            'tanggal'          => $tanggal !== '' ? $tanggal : null,
        ]);

        return $this->ok('Kebijakan SPD berhasil dibuat.', ['id' => $id], 201);
    }
}
